<?php

/**
 * Whether series and collections are on, and who may start a collection.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;

/**
 * Turning a feature off hides its screens and public pages without deleting what is behind it, so the choice can be
 * reversed. Both are on unless switched off for real, so a missing or damaged value keeps the feature working.
 */
final class GroupingSettings
{
    public const DEFAULTS = [
        'series.enabled' => true,
        'collections.enabled' => true,
        'collections.creators' => 'author',
    ];

    /** Who may start a collection: the least powerful role that still may. */
    public const CREATORS = [
        'administrator' => 'Administrators only',
        'editor' => 'Administrators and Editors',
        'author' => 'Administrators, Editors and Authors',
    ];

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    public static function seriesEnabled(Configuration $config): bool
    {
        return $config->setting('series.enabled', true) !== false;
    }

    public static function collectionsEnabled(Configuration $config): bool
    {
        return $config->setting('collections.enabled', true) !== false;
    }

    /**
     * Whether the feature a feed kind belongs to is on. A kind that is not about series or collections is always open.
     */
    public static function kindOn(Configuration $config, string $kind): bool
    {
        return match ($kind) {
            'series' => self::seriesEnabled($config),
            'collection' => self::collectionsEnabled($config),
            default => true,
        };
    }

    public static function creators(Configuration $config): string
    {
        $value = $config->setting('collections.creators', self::DEFAULTS['collections.creators']);

        return is_string($value) && isset(self::CREATORS[$value]) ? $value : self::DEFAULTS['collections.creators'];
    }

    /**
     * Whether this person may start a collection under the site's rule. Suspended accounts hold no capability at all.
     */
    public static function mayStartCollection(Configuration $config, User $user): bool
    {
        return match (self::creators($config)) {
            'administrator' => $user->can(Capability::ManageSite),
            'editor' => $user->can(Capability::EditAnyStory),
            default => $user->can(Capability::WriteStories),
        };
    }

    /**
     * Takes the screens of a switched-off feature out of the admin menu.
     */
    public static function hideFromMenu(HookManager $hooks, Configuration $config): void
    {
        $hooks->addFilter('admin_menu', static function (mixed $items) use ($config): mixed {
            if (!is_array($items)) {
                return $items;
            }

            $hidden = [];

            if (!self::seriesEnabled($config)) {
                $hidden[] = 'stories-series';
            }

            if (!self::collectionsEnabled($config)) {
                $hidden[] = 'stories-collections';
            }

            if ($hidden === []) {
                return $items;
            }

            foreach ($items as $index => $item) {
                if (is_array($item) && is_array($item['children'] ?? null)) {
                    $items[$index]['children'] = array_values(array_filter($item['children'], static fn (mixed $child): bool => !is_array($child) || !in_array($child['slug'] ?? '', $hidden, true)));
                }
            }

            return $items;
        }, 5);
    }

    /**
     * @return array{series_enabled: bool, collections_enabled: bool, collections_creators: string}
     */
    public function current(): array
    {
        return [
            'series_enabled' => self::seriesEnabled($this->config),
            'collections_enabled' => self::collectionsEnabled($this->config),
            'collections_creators' => self::creators($this->config),
        ];
    }

    /**
     * @return array{errors: array<string, string>, values: array{series_enabled: bool, collections_enabled: bool, collections_creators: string}}
     */
    public function validate(bool $seriesEnabled, bool $collectionsEnabled, string $creators): array
    {
        $errors = [];

        if (!isset(self::CREATORS[$creators])) {
            $errors['collections_creators'] = 'Choose who may start a collection.';
            $creators = self::creators($this->config);
        }

        return ['errors' => $errors, 'values' => ['series_enabled' => $seriesEnabled, 'collections_enabled' => $collectionsEnabled, 'collections_creators' => $creators]];
    }

    /**
     * @param array{series_enabled: bool, collections_enabled: bool, collections_creators: string} $values
     * @return list<string> the names of the settings that changed
     */
    public function save(array $values, User $actor, string $ipAddress): array
    {
        if (!$actor->can(Capability::ManageSite)) {
            return [];
        }

        $current = $this->current();
        $changes = [];

        foreach (['series_enabled' => 'series.enabled', 'collections_enabled' => 'collections.enabled', 'collections_creators' => 'collections.creators'] as $field => $setting) {
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

            $this->audit->record($actor->id, 'settings.groupings.update', 'settings', 'groupings', $changes, $ipAddress);
        });

        return array_keys($changes);
    }
}
