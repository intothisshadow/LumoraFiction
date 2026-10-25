<?php

/**
 * Carries out updates, restores and backups one step at a time, with rollback, resume and cancel.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Database\MigrationException;
use LumoraFiction\Core\Database\Migrator;
use LumoraFiction\Core\Errors\ErrorHandler;
use LumoraFiction\Core\Http\MaintenanceFlag;
use LumoraFiction\Core\Update\CompatibilityChecker;
use LumoraFiction\Core\Update\FileSync;
use LumoraFiction\Core\Update\PackageInspector;
use LumoraFiction\Core\Update\UpdateException;
use LumoraFiction\Core\Update\UpdateLayout;
use LumoraFiction\Core\Update\UpdatePackage;
use LumoraFiction\Core\Update\UpdateRun;
use LumoraFiction\Core\Update\UpdateStorage;
use Throwable;

/**
 * The work is split into steps, each its own web request, for three reasons. A host that cuts a request off after thirty
 * seconds can still finish a large backup, because a step returns a cursor and is called again. The step after the files are
 * replaced runs under the new code, which proves the new version starts. And an interrupted run is simply a saved position
 * that can be continued or undone.
 *
 * The one rule that keeps this safe: nothing is replaced until a complete, verified backup of the files and the database
 * exists, and after that point any failure rolls back to that backup by itself. Restoring the files alone would not undo a
 * migration, so a rollback restores the database dump too.
 */
final class UpdateService
{
    public const STEP_LABELS = [
        'stage' => 'Unpacking and checking the package',
        'maintenance_on' => 'Taking the site offline for visitors',
        'backup_files' => 'Backing up the files',
        'backup_database' => 'Backing up the database',
        'apply' => 'Installing the new files and updating the database',
        'verify' => 'Checking the new version',
        'restore_files' => 'Restoring the files',
        'restore_database' => 'Restoring the database',
        'rollback_files' => 'Restoring the earlier files',
        'rollback_database' => 'Restoring the earlier database',
        'rollback_finish' => 'Putting the site back online',
        'finish' => 'Finishing up',
    ];

    private const UPDATE_STEPS = ['stage', 'maintenance_on', 'backup_files', 'backup_database', 'apply', 'verify', 'finish'];

    private const RESTORE_STEPS = ['maintenance_on', 'backup_files', 'backup_database', 'restore_files', 'restore_database', 'finish'];

    private const BACKUP_STEPS = ['backup_files', 'backup_database', 'finish'];

    private const ROLLBACK_STEPS = ['rollback_files', 'rollback_database', 'rollback_finish'];

    /** Shipped with every release; an update never leaves it out, because it is the theme the site falls back to. */
    private const ALWAYS_INSTALLED = ['content/themes/storybook'];

    public function __construct(
        private readonly UpdateStorage $storage,
        private readonly BackupService $backups,
        private readonly PackageInspector $inspector,
        private readonly CompatibilityChecker $compatibility,
        private readonly Migrator $migrator,
        private readonly MaintenanceMode $maintenance,
        private readonly AuditLogService $audit,
        private readonly ErrorHandler $errors,
        private readonly ?SiteAlerts $alerts = null,
        private readonly float $budgetSeconds = 12.0,
    ) {
    }

    public function current(): ?UpdateRun
    {
        $data = $this->storage->readJson($this->storage->statePath());

        return $data === null ? null : UpdateRun::fromArray($data);
    }

    /**
     * The maintenance marker of an update that is no longer running, for example after the state file was deleted by hand.
     *
     * @return array{since: string, reason: string, message: string}|null
     */
    public function strandedMaintenance(): ?array
    {
        return $this->current() === null ? MaintenanceFlag::read($this->storage->root()) : null;
    }

    public function clearStrandedMaintenance(): void
    {
        if ($this->current() === null) {
            MaintenanceFlag::clear($this->storage->root());
        }
    }

    public function installedVersion(): string
    {
        $source = (string) file_get_contents($this->storage->root() . '/version.php');

        return preg_match('/[\'"]version[\'"]\s*=>\s*[\'"](\d+\.\d+\.\d+)[\'"]/', $source, $match) === 1 ? $match[1] : '0.0.0';
    }

    /**
     * Accepts an uploaded package: stores it, optionally checks it against a checksum the administrator typed, and inspects it.
     *
     * @throws UpdateException
     */
    public function receiveUpload(string $uploadedFile, ?string $expectedSha256): UpdatePackage
    {
        $this->refuseWhileRunning();
        $this->storage->ensure();

        if (!copy($uploadedFile, $this->storage->packagePath())) {
            throw new UpdateException('The uploaded package could not be saved. Check that the storage folder is writable and has room.');
        }

        if ($expectedSha256 !== null && $expectedSha256 !== '') {
            if (!hash_equals(strtolower(trim($expectedSha256)), (string) hash_file('sha256', $this->storage->packagePath()))) {
                $this->discardPackage();

                throw new UpdateException('The package does not match the SHA-256 checksum you gave, so it was thrown away.');
            }

            return $this->registerPackage('manual', true);
        }

        return $this->registerPackage('manual', false);
    }

    /**
     * Inspects the package that is now at the staging location and remembers where it came from.
     *
     * @param bool $verified whether it was matched against a published checksum
     * @throws UpdateException
     */
    public function registerPackage(string $source, bool $verified): UpdatePackage
    {
        try {
            $package = $this->inspector->inspect($this->storage->packagePath());
        } catch (UpdateException $exception) {
            $this->discardPackage();

            throw $exception;
        }

        $this->storage->writeJson($this->storage->packageInfoPath(), [
            'source' => $source,
            'verified' => $verified,
            'version' => $package->version,
            'sha256' => (string) hash_file('sha256', $this->storage->packagePath()),
            'received_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $package;
    }

    /**
     * What the administrator reviews before confirming: the package, where it came from, every check, and the bundled
     * plugins and themes that would be left out because they were removed.
     *
     * @return array{package: UpdatePackage, source: string, verified: bool, checks: list<array{label: string, status: string, detail: string}>, passes: bool, skipped: list<string>, added: list<string>}|null
     */
    public function review(): ?array
    {
        $info = $this->storage->readJson($this->storage->packageInfoPath());

        if ($info === null || !is_file($this->storage->packagePath())) {
            return null;
        }

        try {
            $package = $this->inspector->inspect($this->storage->packagePath());
        } catch (UpdateException) {
            $this->discardPackage();

            return null;
        }

        $checks = $this->compatibility->check($package, $this->backups->estimateBytes());
        [$skipped, $added] = $this->planPackages($package);

        return [
            'package' => $package,
            'source' => (string) ($info['source'] ?? 'manual'),
            'verified' => ($info['verified'] ?? false) === true,
            'checks' => $checks,
            'passes' => CompatibilityChecker::passes($checks),
            'skipped' => $skipped,
            'added' => $added,
        ];
    }

    public function discardPackage(): void
    {
        foreach ([$this->storage->packagePath(), $this->storage->packageInfoPath()] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @throws UpdateException when the checks do not pass, nothing is staged or another run is unfinished
     */
    public function startUpdate(): UpdateRun
    {
        $this->refuseWhileRunning();
        $review = $this->review();

        if ($review === null) {
            throw new UpdateException('There is no update package waiting to be installed.');
        }

        if (!$review['passes']) {
            throw new UpdateException('This update cannot be installed yet: at least one check did not pass.');
        }

        $run = new UpdateRun(bin2hex(random_bytes(6)), UpdateRun::KIND_UPDATE, $review['source'], $this->installedVersion(), $review['package']->version, self::UPDATE_STEPS, time());
        $run->skipPackages = $review['skipped'];
        $run->addedPackages = $review['added'];
        $run->note('Update from ' . $run->fromVersion . ' to ' . $run->toVersion . ' started.');

        return $this->begin($run);
    }

    /**
     * @throws UpdateException
     */
    public function startBackup(): UpdateRun
    {
        $this->refuseWhileRunning();
        $version = $this->installedVersion();
        $run = new UpdateRun(bin2hex(random_bytes(6)), UpdateRun::KIND_BACKUP, 'manual', $version, $version, self::BACKUP_STEPS, time());
        $run->note('Backup started.');

        return $this->begin($run);
    }

    /**
     * @throws UpdateException when the backup is missing or was never finished
     */
    public function startRestore(string $backupId): UpdateRun
    {
        $this->refuseWhileRunning();

        $record = $this->backups->find($backupId);

        if ($record === null || !$this->backups->isComplete($backupId)) {
            throw new UpdateException('That backup is missing or was never finished, so it cannot be restored.');
        }

        $this->backups->verify($backupId);
        $this->backups->assertRestorable($backupId);

        $run = new UpdateRun(bin2hex(random_bytes(6)), UpdateRun::KIND_RESTORE, 'backup', $this->installedVersion(), (string) ($record['app_version'] ?? ''), self::RESTORE_STEPS, time());
        $run->restoreId = $backupId;
        $run->note('Restore of the backup from ' . (string) ($record['created_at'] ?? '') . ' UTC started.');

        return $this->begin($run);
    }

    /**
     * Does the next piece of work. Returns the run as it stands, which is terminal when this was the last piece.
     *
     * @throws UpdateException when there is no run, or another request is already working on it
     */
    public function advance(): UpdateRun
    {
        return $this->locked(function (): UpdateRun {
            $run = $this->current();

            if ($run === null) {
                throw new UpdateException('There is nothing in progress.');
            }

            // Resuming after a failure simply tries the step again.
            if ($run->status === UpdateRun::ATTENTION) {
                $run->status = $run->steps === self::ROLLBACK_STEPS ? UpdateRun::ROLLING_BACK : UpdateRun::RUNNING;
            }

            $step = $run->step();
            $deadline = microtime(true) + $this->budgetSeconds;

            try {
                $finished = $step === null || $this->runStep($run, $step, $deadline);
            } catch (UpdateException $exception) {
                $this->fail($run, $exception);
                $this->save($run);

                return $run;
            } catch (Throwable $throwable) {
                $this->errors->report($throwable);
                $this->fail($run, new UpdateException('An unexpected error stopped this step. The details are in the error log.', 0, $throwable));
                $this->save($run);

                return $run;
            }

            if ($finished && !$run->isTerminal()) {
                $run->position++;
                $run->cursor = [];
            }

            $this->save($run);

            return $run;
        });
    }

    /**
     * Stops what can still be stopped. Before anything is replaced that simply ends the run; afterwards it rolls back.
     */
    public function cancel(): UpdateRun
    {
        return $this->locked(function (): UpdateRun {
            $run = $this->current();

            if ($run === null) {
                throw new UpdateException('There is nothing in progress.');
            }

            if ($run->status === UpdateRun::ROLLING_BACK) {
                return $run;
            }

            if ($this->canRollBack($run)) {
                $this->beginRollback($run, 'Cancelled by an administrator after the update had started.');
                $this->save($run);

                return $run;
            }

            if ($run->backupId !== null && !$this->backups->isComplete($run->backupId)) {
                $this->backups->delete($run->backupId);
            }

            $run->message = 'Cancelled by an administrator.';
            $this->settle($run, UpdateRun::CANCELLED);
            $this->save($run);

            return $run;
        });
    }

    /**
     * @return list<array{label: string, state: string}> each step with whether it is done, current or still to come
     */
    public function progress(UpdateRun $run): array
    {
        $steps = [];

        foreach ($run->steps as $index => $step) {
            $state = $index < $run->position || $run->isTerminal() ? 'done' : ($index === $run->position ? 'current' : 'pending');
            $steps[] = ['label' => self::STEP_LABELS[$step] ?? $step, 'state' => $state];
        }

        return $steps;
    }

    /**
     * @return list<array<string, mixed>> newest first
     */
    public function history(int $limit = 15): array
    {
        return $this->storage->history($limit);
    }

    private function begin(UpdateRun $run): UpdateRun
    {
        $this->storage->ensure();
        $this->save($run);
        $this->storage->log('[' . $run->id . '] ' . ucfirst($run->kind) . ' started (' . $run->fromVersion . ' to ' . $run->toVersion . ').');

        return $run;
    }

    private function refuseWhileRunning(): void
    {
        if ($this->current() !== null) {
            throw new UpdateException('An update, restore or backup is already in progress. Finish or cancel it first.');
        }
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function locked(callable $work): mixed
    {
        $this->storage->ensure();
        $handle = fopen($this->storage->lockPath(), 'c');

        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new UpdateException('A step is still running. Wait a moment and try again.');
        }

        try {
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function save(UpdateRun $run): void
    {
        if ($run->isTerminal()) {
            if (is_file($this->storage->statePath())) {
                unlink($this->storage->statePath());
            }

            return;
        }

        $run->updatedAt = time();
        $this->storage->writeJson($this->storage->statePath(), $run->toArray());
    }

    /**
     * @return bool true when the step is finished, false when it should be called again
     */
    private function runStep(UpdateRun $run, string $step, float $deadline): bool
    {
        return match ($step) {
            'stage' => $this->stage($run),
            'maintenance_on' => $this->maintenanceOn($run),
            'backup_files' => $this->backupFiles($run),
            'backup_database' => $this->backupDatabase($run, $deadline),
            'apply' => $this->apply($run),
            'verify' => $this->verify($run),
            'restore_files' => $this->restoreFiles($run, (string) $run->restoreId),
            'restore_database' => $this->restoreDatabase($run, (string) $run->restoreId, $deadline),
            'rollback_files' => $this->rollbackFiles($run),
            'rollback_database' => $this->restoreDatabase($run, (string) $run->backupId, $deadline),
            'rollback_finish' => $this->rollbackFinish($run),
            'finish' => $this->finish($run),
            default => throw new UpdateException('This step is not one this version knows. Cancel and start again.'),
        };
    }

    private function stage(UpdateRun $run): bool
    {
        $this->storage->removeTree($this->storage->stagingDir());
        $package = $this->inspector->inspect($this->storage->packagePath());

        if ($package->version !== $run->toVersion) {
            throw new UpdateException('The package changed since it was reviewed. Cancel and start again.');
        }

        $this->inspector->extract($this->storage->packagePath(), $package, $this->storage->stagingDir());
        $run->note('Package unpacked and every PHP file parsed (' . count($package->files) . ' files).');

        return true;
    }

    private function maintenanceOn(UpdateRun $run): bool
    {
        if (!MaintenanceFlag::raise($this->storage->root(), $run->kind, $this->maintenance->message())) {
            throw new UpdateException('The site could not be taken offline: the storage folder cannot be written to.');
        }

        $run->note('Visitors now see the maintenance page; administrators keep working.');

        return true;
    }

    private function backupFiles(UpdateRun $run): bool
    {
        if ($run->backupId === null) {
            $kind = match ($run->kind) {
                UpdateRun::KIND_UPDATE => BackupService::KIND_UPDATE,
                UpdateRun::KIND_RESTORE => BackupService::KIND_RESTORE,
                default => BackupService::KIND_MANUAL,
            };
            $run->backupId = (string) $this->backups->begin($kind)['id'];
        }

        $count = $this->backups->backupFiles($run->backupId);
        $run->note('Files backed up (' . $count . ' files).');

        return true;
    }

    private function backupDatabase(UpdateRun $run, float $deadline): bool
    {
        $cursor = $this->backups->backupDatabase((string) $run->backupId, $run->cursor, $deadline);
        $run->cursor = $cursor;

        if ($cursor['done'] !== true) {
            $run->note('Database backup in progress (' . $cursor['rows'] . ' rows so far).');

            return false;
        }

        $this->backups->finish((string) $run->backupId);
        $run->cursor = [];
        $run->note('Database backed up (' . $cursor['rows'] . ' rows) and the backup verified.');

        return true;
    }

    private function apply(UpdateRun $run): bool
    {
        if (!$this->backups->isComplete((string) $run->backupId)) {
            throw new UpdateException('There is no finished backup to fall back on, so nothing was changed.');
        }

        if (!$run->destructive) {
            (new FileSync($this->storage->root()))->assertWritable($this->storage->stagingDir(), $run->skipPackages);
        }

        // Saved before the first file is replaced: from here on a crash must be treated as a half-installed update.
        $run->destructive = true;
        $this->save($run);
        $this->warmUp();

        $report = (new FileSync($this->storage->root()))->apply($this->storage->stagingDir(), $run->skipPackages);
        $this->flushOpcache();
        $run->note('Files installed (' . $report['written'] . ' written, ' . $report['removed'] . ' removed).');

        if ($report['skipped'] !== []) {
            $run->note('Left out because they were removed from this site: ' . implode(', ', $report['skipped']) . '.');
        }

        // The migrator already in memory reads the new migration files from disk; they are plain SQL.
        try {
            $executed = $this->migrator->migrate();
        } catch (MigrationException $exception) {
            throw new UpdateException('A database change failed, so the update is being undone.', 0, $exception);
        }

        $run->note($executed === [] ? 'No database changes were needed.' : 'Database changes applied: ' . implode(', ', $executed) . '.');

        return true;
    }

    /**
     * Runs as the first request under the new code, so reaching it at all shows the new version starts.
     */
    private function verify(UpdateRun $run): bool
    {
        $installed = $this->installedVersion();

        if ($installed !== $run->toVersion) {
            throw new UpdateException('The files say version ' . $installed . ' but ' . $run->toVersion . ' was expected.');
        }

        if ($this->migrator->pending() !== []) {
            throw new UpdateException('Some database changes did not run.');
        }

        $run->note('Version ' . $installed . ' is installed and the database is up to date.');

        return true;
    }

    private function restoreFiles(UpdateRun $run, string $backupId): bool
    {
        // Checked before the run counts as having changed anything: a backup that no longer checks out must fail here, while
        // the site is still whole, and not be "rolled back" to.
        if (!$run->destructive) {
            $this->backups->verify($backupId);
            $this->backups->assertRestorable($backupId);
        }

        $run->destructive = true;
        $this->save($run);
        $this->warmUp();

        $report = $this->backups->restoreFiles($backupId);
        $this->flushOpcache();
        $run->note('Files restored (' . $report['written'] . ' written, ' . $report['removed'] . ' removed).');

        return true;
    }

    private function restoreDatabase(UpdateRun $run, string $backupId, float $deadline): bool
    {
        $cursor = $this->backups->restoreDatabase($backupId, $run->cursor, $deadline);
        $run->cursor = $cursor;

        if ($cursor['done'] !== true) {
            $run->note('Database restore in progress (' . $cursor['statements'] . ' statements so far).');

            return false;
        }

        $run->cursor = [];
        $run->note('Database restored.');

        return true;
    }

    private function rollbackFiles(UpdateRun $run): bool
    {
        $this->restoreFiles($run, (string) $run->backupId);

        // A plugin or theme the update added did not exist when the backup was taken, so the restore does not know it.
        foreach ($run->addedPackages as $package) {
            if (UpdateLayout::packageOf($package . '/x') === $package && is_dir($this->storage->root() . '/' . $package)) {
                $this->storage->removeTree($this->storage->root() . '/' . $package);
            }
        }

        return true;
    }

    private function rollbackFinish(UpdateRun $run): bool
    {
        $this->cleanUp($run);
        $this->settle($run, UpdateRun::ROLLED_BACK);

        return true;
    }

    private function finish(UpdateRun $run): bool
    {
        if ($run->kind === UpdateRun::KIND_UPDATE) {
            $this->recordBundledPackages();
            $this->alerts?->clear(UpdateChecker::ALERT_KEY);
        }

        $this->cleanUp($run);
        $this->settle($run, UpdateRun::COMPLETE);

        return true;
    }

    /**
     * Records which bundled plugins and themes this release ships, so the next update can tell a package the administrator
     * removed from one the release has newly added.
     */
    private function recordBundledPackages(): void
    {
        $packages = [];

        foreach (UpdateLayout::PACKAGE_PARENTS as $parent) {
            foreach (glob($this->storage->stagingDir() . '/' . $parent . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
                $packages[] = $parent . '/' . basename($directory);
            }
        }

        if ($packages !== []) {
            $this->storage->writeJson($this->storage->bundledPath(), ['version' => $this->installedVersion(), 'packages' => $packages]);
        }
    }

    private function cleanUp(UpdateRun $run): void
    {
        if ($run->kind !== UpdateRun::KIND_BACKUP) {
            MaintenanceFlag::clear($this->storage->root());
        }

        $this->storage->removeTree($this->storage->stagingDir());
        $this->discardPackage();
    }

    /**
     * Ends the run: writes the history and the log, and tells the audit log when the database can still be reached.
     */
    private function settle(UpdateRun $run, string $status): void
    {
        if ($status === UpdateRun::CANCELLED) {
            $this->cleanUp($run);
        }

        $run->status = $status;
        $result = ['complete' => 'success', 'rolled_back' => 'rolled_back', 'cancelled' => 'cancelled'][$status];
        $message = match ($status) {
            UpdateRun::COMPLETE => match ($run->kind) {
                UpdateRun::KIND_UPDATE => 'Updated from ' . $run->fromVersion . ' to ' . $run->toVersion . '.',
                UpdateRun::KIND_RESTORE => 'Restored a backup.',
                default => 'Backup created.',
            },
            UpdateRun::ROLLED_BACK => 'Rolled back: the site is as it was before the ' . $run->kind . '. Reason: ' . $run->message,
            default => $run->message,
        };

        $run->note($message, $status === UpdateRun::COMPLETE);

        $this->storage->addHistory([
            'time' => gmdate('Y-m-d H:i:s'),
            'kind' => $run->kind,
            'source' => $run->source,
            'from' => $run->fromVersion,
            'to' => $run->toVersion,
            'result' => $result,
            'message' => $message,
            'backup' => $run->backupId,
        ]);
        $this->storage->log('[' . $run->id . '] ' . ucfirst($run->kind) . ' ' . $result . ': ' . $message);

        try {
            $this->audit->record(null, $run->kind . '.' . $result, 'update', $run->id, ['from' => $run->fromVersion, 'to' => $run->toVersion, 'backup' => $run->backupId]);
        } catch (Throwable) {
            // After a rollback the database may have just been replaced; the update log above is the record that counts.
        }
    }

    private function canRollBack(UpdateRun $run): bool
    {
        return $run->destructive
            && in_array($run->kind, [UpdateRun::KIND_UPDATE, UpdateRun::KIND_RESTORE], true)
            && $run->backupId !== null
            && $this->backups->isComplete($run->backupId);
    }

    private function fail(UpdateRun $run, UpdateException $exception): void
    {
        $previous = $exception->getPrevious();

        if ($previous !== null) {
            $this->errors->report($previous);
        }

        $run->message = $exception->getMessage();
        $run->note($exception->getMessage(), false);
        $this->storage->log('[' . $run->id . '] Step ' . ($run->step() ?? '?') . ' failed: ' . $exception->getMessage());

        if ($run->status === UpdateRun::ROLLING_BACK) {
            $run->status = UpdateRun::ATTENTION;

            return;
        }

        if ($this->canRollBack($run)) {
            $this->beginRollback($run, $exception->getMessage());

            return;
        }

        $run->status = UpdateRun::ATTENTION;
    }

    private function beginRollback(UpdateRun $run, string $reason): void
    {
        $run->status = UpdateRun::ROLLING_BACK;
        $run->steps = self::ROLLBACK_STEPS;
        $run->position = 0;
        $run->cursor = [];
        $run->message = $reason;
        $run->note('Going back to the backup taken before the ' . $run->kind . '.', false);
    }

    /**
     * @return array{0: list<string>, 1: list<string>} the packages to leave out and the packages that are new to this site
     */
    private function planPackages(UpdatePackage $package): array
    {
        $recorded = $this->storage->readJson($this->storage->bundledPath());
        $previous = is_array($recorded['packages'] ?? null) ? array_map('strval', $recorded['packages']) : null;
        $skipped = [];
        $added = [];

        foreach ($package->packages as $directory) {
            if (is_dir($this->storage->root() . '/' . $directory)) {
                continue;
            }

            // Gone from the site: if the running release shipped it, the administrator removed it; if not, it is new.
            if (!in_array($directory, self::ALWAYS_INSTALLED, true) && ($previous === null || in_array($directory, $previous, true))) {
                $skipped[] = $directory;

                continue;
            }

            $added[] = $directory;
        }

        return [$skipped, $added];
    }

    /**
     * Loads the classes the steps still need after the files change, so none of them is read from the new release halfway
     * through a request that began on the old one.
     */
    private function warmUp(): void
    {
        foreach ([UpdateException::class, MigrationException::class, UpdateRun::class, FileSync::class, UpdateLayout::class] as $class) {
            class_exists($class);
        }
    }

    private function flushOpcache(): void
    {
        if (!function_exists('opcache_reset') || !filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $restricted = (string) ini_get('opcache.restrict_api');

        if ($restricted !== '' && !str_starts_with((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''), $restricted)) {
            return;
        }

        opcache_reset();
    }
}
