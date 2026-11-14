<?php

/**
 * The administrator's settings for letting authors import their own stories from other archives.
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
 * Two switches: whether the import screens exist at all, and whether they are open to every writer or only to staff.
 * A damaged stored value falls back to the default, so a bad row can never open the import to everyone.
 */
final class ImportSettings
{
    public const DEFAULTS = [
        'import.enabled' => true,
        'import.staff_only' => false,
    ];

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    public static function enabled(Configuration $config): bool
    {
        return $config->setting('import.enabled', true) !== false;
    }

    public static function staffOnly(Configuration $config): bool
    {
        return $config->setting('import.staff_only', false) === true;
    }

    /**
     * Whether this person may import. The service asks this again on every request, so hiding the menu entry is never
     * the only protection.
     */
    public static function mayImport(Configuration $config, User $user): bool
    {
        if (!self::enabled($config) || !$user->can(Capability::WriteStories)) {
            return false;
        }

        return !self::staffOnly($config) || $user->can(Capability::EditAnyStory);
    }

    /**
     * Takes the Import entry out of the menu while the feature is off, and shows it only to staff while the screens are
     * staff-only (the menu already filters each entry by the capability it names).
     */
    public static function hideFromMenu(HookManager $hooks, Configuration $config): void
    {
        $hooks->addFilter('admin_menu', static function (mixed $items) use ($config): mixed {
            if (!is_array($items) || (self::enabled($config) && !self::staffOnly($config))) {
                return $items;
            }

            $off = !self::enabled($config);

            foreach ($items as $index => $item) {
                if (!is_array($item) || !is_array($item['children'] ?? null)) {
                    continue;
                }

                $children = [];

                foreach ($item['children'] as $child) {
                    if (is_array($child) && ($child['slug'] ?? '') === 'stories-import') {
                        if ($off) {
                            continue;
                        }

                        $child['capability'] = Capability::EditAnyStory;
                    }

                    $children[] = $child;
                }

                $items[$index]['children'] = $children;
            }

            return $items;
        }, 5);
    }

    /**
     * @return array{import_enabled: bool, import_staff_only: bool}
     */
    public function current(): array
    {
        return ['import_enabled' => self::enabled($this->config), 'import_staff_only' => self::staffOnly($this->config)];
    }

    /**
     * @param array{import_enabled: bool, import_staff_only: bool} $values
     * @return list<string> the settings that changed
     */
    public function save(array $values, User $actor, string $ipAddress): array
    {
        if (!$actor->can(Capability::ManageSite)) {
            return [];
        }

        $current = $this->current();
        $changes = [];

        foreach (['import_enabled' => 'import.enabled', 'import_staff_only' => 'import.staff_only'] as $field => $setting) {
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

            $this->audit->record($actor->id, 'settings.import.update', 'settings', 'import', $changes, $ipAddress);
        });

        return array_keys($changes);
    }
}
