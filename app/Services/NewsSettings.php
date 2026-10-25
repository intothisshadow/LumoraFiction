<?php

/**
 * Whether news is on, and how many posts the home page shows.
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
 * Turning news off hides its menu entry and public pages and keeps the posts, as the taxonomy switches do. The
 * readers are static so a controller or a navigation hook can ask without holding a settings object; a damaged
 * value falls back to the default instead of hiding or flooding the home page.
 */
final class NewsSettings
{
    public const DEFAULT_HOME_COUNT = 3;

    public const MAX_HOME_COUNT = 20;

    public const DEFAULTS = ['news.enabled' => true, 'news.home_count' => self::DEFAULT_HOME_COUNT];

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * Only a real "false" switches news off, so a missing or damaged value leaves the feature as it ships.
     */
    public static function enabled(Configuration $config): bool
    {
        return $config->setting('news.enabled', true) !== false;
    }

    /**
     * How many posts the home page shows; 0 hides the block.
     */
    public static function homeCount(Configuration $config): int
    {
        $value = $config->setting('news.home_count', self::DEFAULT_HOME_COUNT);

        return is_int($value) && $value >= 0 && $value <= self::MAX_HOME_COUNT ? $value : self::DEFAULT_HOME_COUNT;
    }

    /**
     * @return array{enabled: bool, home_count: int}
     */
    public function current(): array
    {
        return ['enabled' => self::enabled($this->config), 'home_count' => self::homeCount($this->config)];
    }

    /**
     * @param array{enabled: bool, home_count: ?string} $input
     * @return array{errors: array<string, string>, values: array{enabled: bool, home_count: int}}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $count = trim((string) $input['home_count']);

        if (preg_match('/^\d{1,2}\z/', $count) === 1 && (int) $count <= self::MAX_HOME_COUNT) {
            $number = (int) $count;
        } else {
            $errors['home_count'] = 'Enter a number from 0 to ' . self::MAX_HOME_COUNT . '. Use 0 to leave news off the home page.';
            $number = $this->current()['home_count'];
        }

        return ['errors' => $errors, 'values' => ['enabled' => $input['enabled'], 'home_count' => $number]];
    }

    /**
     * @param array{enabled: bool, home_count: int} $values
     * @return list<string> the names of the settings that changed
     */
    public function save(array $values, User $actor, string $ipAddress): array
    {
        if (!$actor->can(Capability::ManageSite)) {
            return [];
        }

        $current = $this->current();
        $changes = [];

        foreach (['enabled' => 'news.enabled', 'home_count' => 'news.home_count'] as $field => $setting) {
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

            $this->audit->record($actor->id, 'settings.news.update', 'settings', 'news', $changes, $ipAddress);
        });

        return array_keys($changes);
    }
}
