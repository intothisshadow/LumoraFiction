<?php

/**
 * Whether this server and this site can take a given release, decided before anything is touched.
 *
 * @package LumoraFiction
 * @subpackage Update
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Update;

use FilesystemIterator;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Requirements;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The requirements are the new release's own, read from its package, not the ones the installed copy was written
 * against: a release that needs a newer PHP or database says so itself. Each check returns a plain sentence, and any
 * "error" stops the update.
 */
final class CompatibilityChecker
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    private const MARGIN_BYTES = 20 * 1024 * 1024;

    /**
     * @param callable(string): (float|false)|null $freeSpace replaces disk_free_space(), for tests
     */
    public function __construct(
        private readonly UpdateStorage $storage,
        private readonly Database $database,
        private readonly string $installedVersion,
        private readonly string $phpVersion = PHP_VERSION,
        private readonly mixed $freeSpace = null,
    ) {
    }

    /**
     * @return list<array{label: string, status: string, detail: string}>
     */
    public function check(UpdatePackage $package, int $backupEstimateBytes): array
    {
        return [
            $this->version($package),
            $this->php($package->minimumPhp),
            $this->extensions($package->extensions),
            $this->databaseServer($package),
            $this->migrations($package),
            $this->writable($this->mirroredRoots($package), $package->packages !== []),
            $this->space($package->unpackedBytes, $backupEstimateBytes),
            $this->configuration(),
        ];
    }

    /**
     * The checks that do not depend on a package, for the Updates screen to show before any is downloaded. They are the
     * same checks the review runs, asked about the installed release's own needs and every folder an update replaces,
     * so a row that fails here is a row that would stop an update there.
     *
     * @return list<array{label: string, status: string, detail: string}>
     */
    public function system(int $backupEstimateBytes): array
    {
        return [
            $this->php(Requirements::MINIMUM_PHP_VERSION),
            $this->extensions(Requirements::REQUIRED_EXTENSIONS),
            $this->downloads(),
            $this->writable(UpdateLayout::REPLACED_DIRECTORIES, true),
            $this->space(0, $backupEstimateBytes),
            $this->storageFolder(),
        ];
    }

    /**
     * @param list<array{label: string, status: string, detail: string}> $checks
     */
    public static function passes(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] === self::ERROR) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function version(UpdatePackage $package): array
    {
        $label = 'Version';

        if (version_compare($package->version, $this->installedVersion, '<=')) {
            return $this->result($label, self::ERROR, 'This package is version ' . $package->version . ', which is not newer than the ' . $this->installedVersion . ' you are running. Updates only go forward.');
        }

        return $this->result($label, self::OK, 'Update from ' . $this->installedVersion . ' to ' . $package->version . '.');
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function php(string $minimum): array
    {
        $label = 'PHP version';

        if (version_compare($this->phpVersion, $minimum, '<')) {
            return $this->result($label, self::ERROR, 'This release needs PHP ' . $minimum . ' or newer, and this server runs ' . $this->phpVersion . '.');
        }

        return $this->result($label, self::OK, 'PHP ' . $this->phpVersion . ' meets the ' . $minimum . ' this release needs.');
    }

    /**
     * @param list<string> $names
     * @return array{label: string, status: string, detail: string}
     */
    private function extensions(array $names): array
    {
        $missing = array_values(array_filter($names, static fn (string $name): bool => !extension_loaded($name)));

        if ($missing !== []) {
            return $this->result('PHP extensions', self::ERROR, 'This release needs PHP extensions this server does not have: ' . implode(', ', $missing) . '.');
        }

        return $this->result('PHP extensions', self::OK, 'Every extension this release needs is available.');
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function databaseServer(UpdatePackage $package): array
    {
        $label = 'Database server';
        $reported = (string) $this->database->fetchColumn('SELECT VERSION()');
        $isMariaDb = stripos($reported, 'mariadb') !== false;
        $clean = (string) preg_replace('/^5\.5\.5-/', '', $reported);

        if (preg_match('/^(\d+\.\d+\.\d+)/', $clean, $match) !== 1) {
            return $this->result($label, self::WARNING, 'The database server did not report a version this page can read, so it could not be checked.');
        }

        $minimum = $isMariaDb ? $package->minimumMariadb : $package->minimumMysql;

        if (version_compare($match[1], $minimum, '<')) {
            return $this->result($label, self::ERROR, 'This release needs ' . ($isMariaDb ? 'MariaDB ' : 'MySQL ') . $minimum . ' or newer, and this server runs ' . $match[1] . '.');
        }

        return $this->result($label, self::OK, ($isMariaDb ? 'MariaDB ' : 'MySQL ') . $match[1] . ' meets the ' . $minimum . ' this release needs.');
    }

    /**
     * A database that has run a migration the package does not contain was made by a newer release, and rolling it
     * back is not something an update does.
     *
     * @return array{label: string, status: string, detail: string}
     */
    private function migrations(UpdatePackage $package): array
    {
        $label = 'Database changes';
        $applied = array_column($this->database->fetchAll('SELECT migration FROM {prefix}migrations'), 'migration');
        $unknown = array_values(array_diff($applied, $package->migrations));

        if ($unknown !== []) {
            return $this->result($label, self::ERROR, 'The database has changes this package does not know about (' . $unknown[0] . '), so it came from a newer release. Do not go backwards with an update.');
        }

        $new = count(array_diff($package->migrations, $applied));

        return $this->result($label, self::OK, $new === 0 ? 'The release has no database changes.' : $new . ' database change' . ($new === 1 ? '' : 's') . ' will be applied.');
    }

    /**
     * @return list<string> the application and package folders a package would replace
     */
    private function mirroredRoots(UpdatePackage $package): array
    {
        $roots = [];

        foreach ($package->files as $file) {
            $mirrored = UpdateLayout::mirroredRootOf($file['path']);

            if ($mirrored !== null) {
                $roots[$mirrored] = true;
            }
        }

        return array_keys($roots);
    }

    /**
     * Whether this server can download a release at all: cURL, or PHP's own streams. Without either, only the manual
     * upload works.
     *
     * @return array{label: string, status: string, detail: string}
     */
    private function downloads(): array
    {
        $label = 'Downloads from GitHub';

        if (function_exists('curl_init')) {
            return $this->result($label, self::OK, 'cURL is available, so the server can fetch a release itself.');
        }

        if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return $this->result($label, self::OK, 'PHP can open web addresses, so the server can fetch a release itself.');
        }

        return $this->result($label, self::WARNING, 'This server has neither cURL nor allow_url_fopen, so it cannot reach GitHub. Upload the release ZIP on the Manual Update tab instead.');
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function storageFolder(): array
    {
        $label = 'Update storage';
        $directory = $this->storage->updatesDir();

        if (is_dir($directory) ? !is_writable($directory) : !is_writable(dirname($directory))) {
            return $this->result($label, self::ERROR, 'The web server cannot write to the storage folder where a release is unpacked and backups are kept. Change its permissions (or ask the host to), then check again.');
        }

        return $this->result($label, self::OK, 'The folder where a release is unpacked and backups are kept can be written to.');
    }

    /**
     * @param list<string> $mirroredRoots
     * @return array{label: string, status: string, detail: string}
     */
    private function writable(array $mirroredRoots, bool $addsPackages): array
    {
        $label = 'File permissions';
        $root = $this->storage->root();
        $roots = array_fill_keys($mirroredRoots, true);

        $directories = [$root, $this->storage->updatesDir(), $this->storage->backupsDir(), dirname($this->storage->logPath())];

        // A plugin or theme the release adds is created inside these.
        if ($addsPackages) {
            array_push($directories, ...array_map(static fn (string $parent): string => $root . '/' . $parent, UpdateLayout::PACKAGE_PARENTS));
        }

        $problems = [];

        foreach ($directories as $directory) {
            if (is_dir($directory) && !is_writable($directory)) {
                $problems[] = substr($directory, strlen($root) + 1) ?: '(site folder)';
            }
        }

        foreach (array_keys($roots) as $relative) {
            $directory = $root . '/' . $relative;

            if (is_link($directory)) {
                $problems[] = $relative . ' (a shortcut)';

                continue;
            }

            if (!is_dir($directory)) {
                continue;
            }

            foreach ($this->unwritable($directory) as $path) {
                $problems[] = substr($path, strlen($root) + 1);
            }
        }

        if ($problems !== []) {
            $shown = array_slice($problems, 0, 5);

            return $this->result($label, self::ERROR, 'The web server cannot write to: ' . implode(', ', $shown) . (count($problems) > 5 ? ' and ' . (count($problems) - 5) . ' more' : '') . '. Change these permissions (or ask the host to), then check again.');
        }

        return $this->result($label, self::OK, 'The web server can write to every folder the update changes.');
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function space(int $unpackedBytes, int $backupEstimateBytes): array
    {
        $label = 'Disk space';
        $free = $this->freeBytes($this->storage->root());

        if ($free === null) {
            return $this->result($label, self::WARNING, 'This server did not report its free disk space, so it could not be checked.');
        }

        // The package is kept, unpacked once, and the backup is a compressed copy of the site.
        $needed = $unpackedBytes * 3 + $backupEstimateBytes + self::MARGIN_BYTES;

        if ($free < $needed) {
            return $this->result($label, self::ERROR, 'About ' . $this->megabytes($needed) . ' MB of free disk space is needed for the package and the backup, and ' . $this->megabytes((int) $free) . ' MB is free.');
        }

        return $this->result($label, self::OK, $this->megabytes((int) $free) . ' MB is free; about ' . $this->megabytes($needed) . ' MB is needed.');
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function configuration(): array
    {
        $file = $this->storage->root() . '/config/config.php';

        if (!is_file($file) || !is_readable($file)) {
            return $this->result('Site configuration', self::ERROR, 'The site configuration file cannot be read, so the update could not be verified afterwards.');
        }

        return $this->result('Site configuration', self::OK, 'The configuration file is in place and is never changed by an update.');
    }

    /**
     * @return list<string>
     */
    private function unwritable(string $directory): array
    {
        $found = [];

        if (!is_writable($directory)) {
            return [$directory];
        }

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($items as $item) {
            if ($item->isLink()) {
                continue;
            }

            if (!is_writable($item->getPathname())) {
                $found[] = $item->getPathname();

                if (count($found) >= 6) {
                    break;
                }
            }
        }

        return $found;
    }

    private function freeBytes(string $path): ?float
    {
        $free = $this->freeSpace !== null ? ($this->freeSpace)($path) : (function_exists('disk_free_space') ? disk_free_space($path) : false);

        return $free === false ? null : (float) $free;
    }

    private function megabytes(int $bytes): int
    {
        return (int) ceil($bytes / 1048576);
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function result(string $label, string $status, string $detail): array
    {
        return ['label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
