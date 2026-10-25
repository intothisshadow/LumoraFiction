<?php

/**
 * How many stories each list on the home page shows.
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
 * The counts are read straight from the configuration by the home page, so a damaged value falls back to the usual
 * number instead of emptying or flooding the page.
 */
final class FrontPageSettings
{
    public const DEFAULT_COUNT = 8;

    public const MAX_COUNT = 30;

    public const DEFAULTS = ['home.published_count' => self::DEFAULT_COUNT, 'home.updated_count' => self::DEFAULT_COUNT];

    /** Form field => setting name. */
    private const FIELDS = [
        'published_count' => 'home.published_count',
        'updated_count' => 'home.updated_count',
    ];

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * How many recently published stories the home page lists; 0 leaves the list off.
     */
    public static function publishedCount(Configuration $config): int
    {
        return self::count($config->setting('home.published_count', self::DEFAULT_COUNT));
    }

    /**
     * How many recently updated stories the home page lists; 0 leaves the list off.
     */
    public static function updatedCount(Configuration $config): int
    {
        return self::count($config->setting('home.updated_count', self::DEFAULT_COUNT));
    }

    /**
     * @return array{published_count: int, updated_count: int}
     */
    public function current(): array
    {
        return ['published_count' => self::publishedCount($this->config), 'updated_count' => self::updatedCount($this->config)];
    }

    /**
     * @param array{published_count: ?string, updated_count: ?string} $input
     * @return array{errors: array<string, string>, values: array{published_count: int, updated_count: int}}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $current = $this->current();
        $values = $current;

        foreach (['published_count' => 'recently published', 'updated_count' => 'recently updated'] as $field => $label) {
            $text = trim((string) $input[$field]);

            if (preg_match('/^\d{1,2}\z/', $text) === 1 && (int) $text <= self::MAX_COUNT) {
                $values[$field] = (int) $text;
            } else {
                $errors[$field] = 'Enter how many ' . $label . ' stories to list, from 0 to ' . self::MAX_COUNT . '. Use 0 to leave that list off.';
            }
        }

        return ['errors' => $errors, 'values' => $values];
    }

    /**
     * Saves only what changed, and records the change in the same transaction so the two cannot disagree.
     *
     * @param array{published_count: int, updated_count: int} $values
     * @return list<string> the names of the settings that changed
     */
    public function save(array $values, User $actor, string $ipAddress): array
    {
        if (!$actor->can(Capability::ManageSite)) {
            return [];
        }

        $current = $this->current();
        $changes = [];

        foreach (self::FIELDS as $field => $setting) {
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

            $this->audit->record($actor->id, 'settings.front_page.update', 'settings', 'front_page', $changes, $ipAddress);
        });

        return array_keys($changes);
    }

    private static function count(mixed $value): int
    {
        return is_int($value) && $value >= 0 && $value <= self::MAX_COUNT ? $value : self::DEFAULT_COUNT;
    }
}
