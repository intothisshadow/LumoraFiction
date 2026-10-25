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

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Update\ReleaseFeed;
use LumoraFiction\Core\Update\UpdateException;
use LumoraFiction\Core\Update\UpdateStorage;
use LumoraFiction\Models\AlertSeverity;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;

/**
 * A check only ever happens when an administrator asks for one: nothing here runs on a schedule or on its own, and nothing
 * is downloaded or installed without a further click. The last answer is kept in a file so the Updates screen and the
 * dashboard alert can show it without asking GitHub again.
 */
final class UpdateChecker
{
    public const ALERT_KEY = 'update.available';

    public const CHANNEL_STABLE = 'stable';

    public const CHANNEL_PRERELEASE = 'prerelease';

    public const DEFAULTS = [
        'updates.repository' => ReleaseFeed::DEFAULT_REPOSITORY,
        'updates.channel' => self::CHANNEL_STABLE,
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
     * @return array{repository: string, channel: string}
     */
    public function settings(): array
    {
        $repository = $this->config->setting('updates.repository', ReleaseFeed::DEFAULT_REPOSITORY);
        $channel = $this->config->setting('updates.channel', self::CHANNEL_STABLE);

        return [
            'repository' => is_string($repository) && ReleaseFeed::isValidRepository($repository) ? $repository : ReleaseFeed::DEFAULT_REPOSITORY,
            'channel' => $channel === self::CHANNEL_PRERELEASE ? self::CHANNEL_PRERELEASE : self::CHANNEL_STABLE,
        ];
    }

    /**
     * @param array{repository: ?string, channel: ?string} $input
     * @return array{errors: array<string, string>, values: array{repository: string, channel: string}}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $repository = trim((string) $input['repository']);
        $channel = (string) $input['channel'];

        if (!ReleaseFeed::isValidRepository($repository)) {
            $errors['repository'] = 'Use the form owner/name, for example ' . ReleaseFeed::DEFAULT_REPOSITORY . '.';
            $repository = $this->settings()['repository'];
        }

        if (!in_array($channel, [self::CHANNEL_STABLE, self::CHANNEL_PRERELEASE], true)) {
            $errors['channel'] = 'Choose stable releases or pre-releases.';
            $channel = $this->settings()['channel'];
        }

        return ['errors' => $errors, 'values' => ['repository' => $repository, 'channel' => $channel]];
    }

    /**
     * @param array{repository: string, channel: string} $values
     * @return list<string> the names of the settings that changed
     */
    public function save(array $values, User $actor, string $ipAddress): array
    {
        if (!$actor->can(Capability::ManageSite)) {
            return [];
        }

        $current = $this->settings();
        $changes = [];

        foreach (['repository', 'channel'] as $field) {
            if ($values[$field] !== $current[$field]) {
                $changes['updates.' . $field] = ['from' => $current[$field], 'to' => $values[$field]];
            }
        }

        if ($changes === []) {
            return [];
        }

        $this->database->transaction(function () use ($changes, $actor, $ipAddress): void {
            foreach ($changes as $setting => $change) {
                $this->config->setSetting($setting, $change['to']);
            }

            $this->audit->record($actor->id, 'settings.updates.update', 'settings', 'updates', $changes, $ipAddress);
        });

        // An answer about one repository or channel says nothing about another.
        $this->forget();

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
        $release = $this->feed->latest($settings['repository'], $settings['channel'] === self::CHANNEL_PRERELEASE);
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
        if (is_file($this->storage->latestPath())) {
            unlink($this->storage->latestPath());
        }

        $this->alerts->clear(self::ALERT_KEY);
    }
}
