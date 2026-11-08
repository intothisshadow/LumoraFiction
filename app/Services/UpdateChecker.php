<?php

/**
 * Asks GitHub whether a newer release exists, remembers the answer, and downloads a release for review.
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

use DateTimeImmutable;
use DateTimeZone;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Update\ReleaseFeed;
use LumoraFiction\Core\Update\UpdateException;
use LumoraFiction\Core\Update\UpdateStorage;
use LumoraFiction\Models\AlertSeverity;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;

/**
 * A check happens when an administrator asks for one, or, when they have turned the automatic check on, the next time an
 * administrator opens the admin once one is due. There is no background job, and nothing is downloaded or installed
 * without a further click. The last answer is kept in a file so the Updates screen and the dashboard alert can show it
 * without asking GitHub again.
 */
final class UpdateChecker
{
    public const ALERT_KEY = 'update.available';

    public const CHANNEL_STABLE = 'stable';

    public const CHANNEL_PRERELEASE = 'prerelease';

    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    /** How long a check stays fresh, in seconds. */
    private const INTERVALS = [self::FREQUENCY_DAILY => 86400, self::FREQUENCY_WEEKLY => 604800];

    /** What GitHub's tokens look like: letters, digits and underscores, from the classic 40 characters to the long fine-grained form. */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_]{20,255}\z/';

    public const STATUS_UP_TO_DATE = 'up-to-date';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_NOT_CHECKED = 'not-checked';

    public const STATUS_FAILED = 'failed';

    public const DEFAULTS = [
        'updates.repository' => ReleaseFeed::DEFAULT_REPOSITORY,
        'updates.channel' => self::CHANNEL_STABLE,
        'updates.auto_check' => false,
        'updates.frequency' => self::FREQUENCY_WEEKLY,
        'updates.github_token' => '',
    ];

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
        private readonly ReleaseFeed $feed,
        private readonly UpdateStorage $storage,
        private readonly SiteAlerts $alerts,
        private readonly string $installedVersion,
    ) {
    }

    /**
     * @return array{repository: string, channel: string, auto_check: bool, frequency: string}
     */
    public function settings(): array
    {
        $repository = $this->config->setting('updates.repository', ReleaseFeed::DEFAULT_REPOSITORY);
        $channel = $this->config->setting('updates.channel', self::CHANNEL_STABLE);
        $frequency = $this->config->setting('updates.frequency', self::FREQUENCY_WEEKLY);

        return [
            'repository' => is_string($repository) && ReleaseFeed::isValidRepository($repository) ? $repository : ReleaseFeed::DEFAULT_REPOSITORY,
            'channel' => $channel === self::CHANNEL_PRERELEASE ? self::CHANNEL_PRERELEASE : self::CHANNEL_STABLE,
            'auto_check' => $this->config->setting('updates.auto_check') === true,
            'frequency' => $frequency === self::FREQUENCY_DAILY ? self::FREQUENCY_DAILY : self::FREQUENCY_WEEKLY,
        ];
    }

    /** Whether a token is stored. The token itself is never handed to a screen. */
    public function hasToken(): bool
    {
        return $this->token() !== '';
    }

    private function token(): string
    {
        $token = $this->config->setting('updates.github_token', '');

        return is_string($token) ? $token : '';
    }

    /**
     * Fields left out of the input keep their current value. A token field left empty keeps the stored token; it is
     * removed only by `clear_token`, so saving the form again never wipes it.
     *
     * @param array{repository: ?string, channel: ?string, auto_check?: bool, frequency?: ?string, github_token?: ?string, clear_token?: bool} $input
     * @return array{errors: array<string, string>, values: array{repository: string, channel: string, auto_check: bool, frequency: string}, token: string, clear_token: bool}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $current = $this->settings();
        $repository = trim((string) $input['repository']);
        $channel = (string) $input['channel'];
        $frequency = ($input['frequency'] ?? null) !== null ? (string) $input['frequency'] : $current['frequency'];
        $autoCheck = array_key_exists('auto_check', $input) ? $input['auto_check'] === true : $current['auto_check'];
        $token = trim((string) ($input['github_token'] ?? ''));
        $clear = ($input['clear_token'] ?? false) === true;

        if (!ReleaseFeed::isValidRepository($repository)) {
            $errors['repository'] = 'Use the form owner/name, for example ' . ReleaseFeed::DEFAULT_REPOSITORY . '.';
            $repository = $current['repository'];
        }

        if (!in_array($channel, [self::CHANNEL_STABLE, self::CHANNEL_PRERELEASE], true)) {
            $errors['channel'] = 'Choose stable releases or pre-releases.';
            $channel = $current['channel'];
        }

        if (!isset(self::INTERVALS[$frequency])) {
            $errors['frequency'] = 'Choose daily or weekly.';
            $frequency = $current['frequency'];
        }

        if ($token !== '' && preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            $errors['github_token'] = 'That does not look like a GitHub token: it is letters, numbers and underscores only. Nothing was saved.';
            $token = '';
        }

        return [
            'errors' => $errors,
            'values' => ['repository' => $repository, 'channel' => $channel, 'auto_check' => $autoCheck, 'frequency' => $frequency],
            'token' => $clear ? '' : $token,
            'clear_token' => $clear,
        ];
    }

    /**
     * @param array{repository: string, channel: string, auto_check?: bool, frequency?: string} $values
     * @param string $token a new token, or an empty string to keep the stored one
     * @return list<string> the names of the settings that changed
     */
    public function save(array $values, User $actor, string $ipAddress, string $token = '', bool $clearToken = false): array
    {
        if (!$actor->can(Capability::ManageSite)) {
            return [];
        }

        $current = $this->settings();
        $changes = [];

        foreach (['repository', 'channel', 'auto_check', 'frequency'] as $field) {
            if (array_key_exists($field, $values) && $values[$field] !== $current[$field]) {
                $changes['updates.' . $field] = ['from' => $current[$field], 'to' => $values[$field]];
            }
        }

        $newToken = $clearToken ? '' : ($token !== '' ? $token : $this->token());

        if ($newToken !== $this->token()) {
            // The log notes that the token changed and never what it changed to.
            $changes['updates.github_token'] = ['changed' => true, 'to' => $newToken];
        }

        if ($changes === []) {
            return [];
        }

        $this->database->transaction(function () use ($changes, $actor, $ipAddress): void {
            $logged = [];

            foreach ($changes as $setting => $change) {
                $this->config->setSetting($setting, $change['to']);
                $logged[$setting] = $setting === 'updates.github_token' ? ['changed' => true] : $change;
            }

            $this->audit->record($actor->id, 'settings.updates.update', 'settings', 'updates', $logged, $ipAddress);
        });

        // An answer about one repository or channel says nothing about another.
        if (isset($changes['updates.repository']) || isset($changes['updates.channel'])) {
            $this->forget();
        }

        return array_keys($changes);
    }

    /**
     * Asks GitHub now. The answer is kept either way, so the screen can say when it last looked.
     *
     * @return array{checked_at: string, release: array<string, mixed>|null, newer: bool}
     * @throws UpdateException when GitHub cannot be reached
     */
    public function check(): array
    {
        $settings = $this->settings();

        try {
            $release = $this->feed->latest($settings['repository'], $settings['channel'] === self::CHANNEL_PRERELEASE, $this->token());
        } catch (UpdateException $exception) {
            // Remembered so the Updates screen can open on the manual upload when this host cannot reach GitHub.
            $this->storage->writeJson($this->storage->checkFailurePath(), ['checked_at' => gmdate('Y-m-d H:i:s'), 'repository' => $settings['repository'], 'reason' => $exception->getMessage()]);

            throw $exception;
        }

        if (is_file($this->storage->checkFailurePath())) {
            unlink($this->storage->checkFailurePath());
        }

        $newer = $release !== null && version_compare($release['version'], $this->installedVersion, '>');
        $answer = ['checked_at' => gmdate('Y-m-d H:i:s'), 'release' => $release, 'newer' => $newer, 'repository' => $settings['repository']];

        $this->storage->writeJson($this->storage->latestPath(), $answer);

        if ($newer) {
            $this->alerts->raise(self::ALERT_KEY, AlertSeverity::Info, 'Lumora Fiction ' . $release['version'] . ' is available.', 'You are running ' . $this->installedVersion . '.', 'maintenance/updates');
        } else {
            $this->alerts->clear(self::ALERT_KEY);
        }

        return ['checked_at' => $answer['checked_at'], 'release' => $release, 'newer' => $newer];
    }

    /**
     * What the last check found, or null when none was made (or it was about another repository).
     *
     * @return array{checked_at: string, release: array<string, mixed>|null, newer: bool}|null
     */
    public function last(): ?array
    {
        $answer = $this->storage->readJson($this->storage->latestPath());

        if ($answer === null || ($answer['repository'] ?? '') !== $this->settings()['repository']) {
            return null;
        }

        $release = is_array($answer['release'] ?? null) ? $answer['release'] : null;

        return [
            'checked_at' => (string) ($answer['checked_at'] ?? ''),
            'release' => $release,
            'newer' => $release !== null && version_compare((string) ($release['version'] ?? '0'), $this->installedVersion, '>'),
        ];
    }

    /**
     * When the last check was made, whether it worked or not, as a UTC time; null when none has been made.
     */
    public function lastAttempt(): ?DateTimeImmutable
    {
        $times = [];

        foreach ([$this->last()['checked_at'] ?? '', $this->lastFailure()['checked_at'] ?? ''] as $text) {
            if ($text !== '' && ($time = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $text, new DateTimeZone('UTC'))) !== false) {
                $times[] = $time;
            }
        }

        return $times === [] ? null : max($times);
    }

    /**
     * Whether the automatic check is on and the last attempt, successful or not, is older than the chosen interval.
     * A failed attempt counts, so an unreachable GitHub is asked once per interval and not on every page.
     */
    public function isDue(?DateTimeImmutable $now = null): bool
    {
        $settings = $this->settings();

        if (!$settings['auto_check']) {
            return false;
        }

        $last = $this->lastAttempt();

        return $last === null || ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp() - $last->getTimestamp() >= self::INTERVALS[$settings['frequency']];
    }

    /**
     * Makes the automatic check if one is due. Never throws: a failure is remembered like any other and shown on the screen.
     */
    public function checkIfDue(?DateTimeImmutable $now = null): bool
    {
        if (!$this->isDue($now)) {
            return false;
        }

        try {
            $this->check();
        } catch (UpdateException) {
            // check() has already written the failure down.
        }

        return true;
    }

    /**
     * One word for the badge: whether the newest release is newer than this one, was never looked for, or could not be found.
     */
    public function status(): string
    {
        $failure = $this->lastFailure();
        $last = $this->last();

        if ($failure !== null && ($last === null || strcmp($failure['checked_at'], $last['checked_at']) >= 0)) {
            return self::STATUS_FAILED;
        }

        if ($last === null) {
            return self::STATUS_NOT_CHECKED;
        }

        return $last['newer'] ? self::STATUS_AVAILABLE : self::STATUS_UP_TO_DATE;
    }

    /**
     * Why the last check could not reach GitHub, or null when it did (or none was made, or it was about another repository).
     *
     * @return array{checked_at: string, reason: string}|null
     */
    public function lastFailure(): ?array
    {
        $failure = $this->storage->readJson($this->storage->checkFailurePath());

        if ($failure === null || ($failure['repository'] ?? '') !== $this->settings()['repository'] || !is_string($failure['reason'] ?? null)) {
            return null;
        }

        return ['checked_at' => (string) ($failure['checked_at'] ?? ''), 'reason' => $failure['reason']];
    }

    /**
     * Downloads the release the last check found into the staging location, verified against its published checksum.
     *
     * @throws UpdateException when there is no release to fetch, or the download fails its checksum
     */
    public function download(): void
    {
        $last = $this->last();

        if ($last === null || $last['release'] === null || !$last['newer']) {
            throw new UpdateException('There is no newer release to download. Check for updates first.');
        }

        $this->storage->ensure();
        $release = $last['release'];

        $this->feed->download([
            'package_url' => (string) ($release['package_url'] ?? ''),
            'checksum_url' => (string) ($release['checksum_url'] ?? ''),
            'version' => (string) ($release['version'] ?? ''),
        ], $this->storage->packagePath());
    }

    private function forget(): void
    {
        foreach ([$this->storage->latestPath(), $this->storage->checkFailurePath()] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->alerts->clear(self::ALERT_KEY);
    }
}
