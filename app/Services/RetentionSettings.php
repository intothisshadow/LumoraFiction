<?php

/**
 * How long trashed items and earlier chapter versions are kept.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;

/**
 * The two numbers are read by the services that apply them straight from the configuration, so a damaged value falls back
 * to the default instead of keeping nothing or keeping everything.
 */
final class RetentionSettings
{
    public const DEFAULT_TRASH_DAYS = 30;

    public const DEFAULT_REVISIONS = 20;

    public const MAX_TRASH_DAYS = 3650;

    public const MAX_REVISIONS = 200;

    public const DEFAULTS = ['retention.trash_days' => self::DEFAULT_TRASH_DAYS, 'retention.revisions' => self::DEFAULT_REVISIONS];

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * Days a trashed story or chapter is kept before it is removed for good; 0 keeps them until someone deletes them.
     */
    public static function trashDays(Configuration $config): int
    {
        $value = $config->setting('retention.trash_days', self::DEFAULT_TRASH_DAYS);

        return is_int($value) && $value >= 0 && $value <= self::MAX_TRASH_DAYS ? $value : self::DEFAULT_TRASH_DAYS;
    }

    /**
     * How many earlier versions of a chapter are kept, the newest ones.
     */
    public static function revisionsKept(Configuration $config): int
    {
        $value = $config->setting('retention.revisions', self::DEFAULT_REVISIONS);

        return is_int($value) && $value >= 1 && $value <= self::MAX_REVISIONS ? $value : self::DEFAULT_REVISIONS;
    }

    /**
     * @return array{trash_days: int, revisions: int}
     */
    public function current(): array
    {
        return ['trash_days' => self::trashDays($this->config), 'revisions' => self::revisionsKept($this->config)];
    }

    /**
     * @param array{trash_days: ?string, revisions: ?string} $input
     * @return array{errors: array<string, string>, values: array{trash_days: int, revisions: int}}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $current = $this->current();

        $days = $this->whole($input['trash_days'] ?? null);
        $revisions = $this->whole($input['revisions'] ?? null);

        if ($days === null || $days > self::MAX_TRASH_DAYS) {
            $errors['trash_days'] = 'Enter a number of days from 0 to ' . self::MAX_TRASH_DAYS . '. Use 0 to keep trashed items until you delete them.';
            $days = $current['trash_days'];
        }

        if ($revisions === null || $revisions < 1 || $revisions > self::MAX_REVISIONS) {
            $errors['revisions'] = 'Enter a number of earlier versions from 1 to ' . self::MAX_REVISIONS . '.';
            $revisions = $current['revisions'];
        }

        return ['errors' => $errors, 'values' => ['trash_days' => $days, 'revisions' => $revisions]];
    }

    /**
     * @param array{trash_days: int, revisions: int} $values
     * @return list<string> the names of the settings that changed
     */
    public function save(array $values, User $actor, string $ipAddress): array
    {
        if (!$actor->can(Capability::ManageSite)) {
            return [];
        }

        $current = $this->current();
        $changes = [];

        foreach (['trash_days' => 'retention.trash_days', 'revisions' => 'retention.revisions'] as $field => $setting) {
            if ($values[$field] !== $current[$field]) {
                $changes[$setting] = ['from' => $current[$field], 'to' => $values[$field]];
            }
        }

        if ($changes === []) {
            return [];
        }

        $this->database->transaction(function () use ($changes, $actor, $ipAddress): void {
            foreach ($changes as $setting => $change) {
                $this->config->setSetting($setting, $change['to']);
            }

            $this->audit->record($actor->id, 'settings.retention.update', 'settings', 'retention', $changes, $ipAddress);
        });

        return array_keys($changes);
    }

    private function whole(?string $value): ?int
    {
        $value = trim((string) $value);

        return preg_match('/^\d{1,5}\z/', $value) === 1 ? (int) $value : null;
    }
}
