<?php

/**
 * The admin sidebar: the screens a signed-in user may open, with the current one marked.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Admin;

use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;

/**
 * Only screens that exist are listed. Each feature adds its own entries through the admin_menu
 * filter, so the menu grows with the application and never points at a page that is not there.
 * Hiding an entry is a convenience; each screen still checks the capability itself.
 */
final class AdminMenu
{
    public function __construct(private readonly HookManager $hooks)
    {
    }

    /**
     * @return list<array{slug: string, label: string, path: string, capability: Capability|null, children: list<array{slug: string, label: string, path: string, capability: Capability|null}>}>
     */
    public function definition(): array
    {
        $items = [
            [
                'slug' => 'dashboard',
                'label' => 'Dashboard',
                'path' => '',
                'capability' => Capability::AccessAdmin,
                'children' => [],
            ],
            [
                'slug' => 'stories',
                'label' => 'Stories',
                'path' => 'stories',
                'capability' => Capability::WriteStories,
                'children' => [
                    ['slug' => 'stories-mine', 'label' => 'My stories', 'path' => 'stories', 'capability' => Capability::WriteStories],
                    ['slug' => 'stories-new', 'label' => 'Add a story', 'path' => 'stories/new', 'capability' => Capability::WriteStories],
                    ['slug' => 'stories-all', 'label' => 'All stories', 'path' => 'stories/all', 'capability' => Capability::EditAnyStory],
                    ['slug' => 'stories-review', 'label' => 'Awaiting approval', 'path' => 'review', 'capability' => Capability::ModerateStories],
                ],
            ],
            [
                'slug' => 'profile',
                'label' => 'Author profile',
                'path' => 'profile',
                'capability' => Capability::WriteStories,
                'children' => [],
            ],
            [
                'slug' => 'users',
                'label' => 'Users',
                'path' => 'users',
                'capability' => Capability::ManageUsers,
                'children' => [],
            ],
            [
                'slug' => 'taxonomy',
                'label' => 'Classification',
                'path' => 'taxonomy',
                'capability' => Capability::ManageSite,
                'children' => [],
            ],
            [
                'slug' => 'appearance',
                'label' => 'Appearance',
                'path' => 'appearance',
                'capability' => Capability::ManageSite,
                'children' => [
                    ['slug' => 'appearance-themes', 'label' => 'Themes', 'path' => 'appearance', 'capability' => Capability::ManageSite],
                    ['slug' => 'appearance-options', 'label' => 'Theme options', 'path' => 'appearance/options', 'capability' => Capability::ManageSite],
                ],
            ],
            [
                'slug' => 'settings',
                'label' => 'Settings',
                'path' => 'settings/archive',
                'capability' => Capability::ManageSite,
                'children' => [
                    ['slug' => 'settings-archive', 'label' => 'Archive', 'path' => 'settings/archive', 'capability' => Capability::ManageSite],
                    ['slug' => 'settings-editor', 'label' => 'Editor', 'path' => 'settings/editor', 'capability' => Capability::ManageSite],
                    ['slug' => 'settings-security', 'label' => 'Security', 'path' => 'settings/security', 'capability' => Capability::ManageSite],
                    ['slug' => 'settings-spam', 'label' => 'Spam protection', 'path' => 'settings/spam', 'capability' => Capability::ManageSite],
                ],
            ],
            [
                'slug' => 'maintenance',
                'label' => 'Maintenance',
                'path' => 'maintenance/system-information',
                'capability' => Capability::ManageSite,
                'children' => [
                    ['slug' => 'maintenance-system-information', 'label' => 'System Information', 'path' => 'maintenance/system-information', 'capability' => Capability::ManageSite],
                    ['slug' => 'maintenance-plugins', 'label' => 'Plugins', 'path' => 'maintenance/plugins', 'capability' => Capability::ManageSite],
                    ['slug' => 'maintenance-security-events', 'label' => 'Security events', 'path' => 'maintenance/security-events', 'capability' => Capability::ManageSite],
                    ['slug' => 'maintenance-alerts', 'label' => 'Alerts', 'path' => 'maintenance/alerts', 'capability' => Capability::ManageSite],
                ],
            ],
        ];

        $filtered = $this->hooks->applyFilters('admin_menu', $items);

        return is_array($filtered) ? $this->sanitise($filtered) : $items;
    }

    /**
     * The entries this user may see, ready to print. Addresses are built here, never taken from the filter.
     *
     * @return list<array{label: string, url: string, current: bool, open: bool, children: list<array{label: string, url: string, current: bool}>}>
     */
    public function forUser(User $user, string $currentPath): array
    {
        $currentPath = trim($currentPath, '/');
        $visible = [];

        foreach ($this->definition() as $item) {
            if ($item['capability'] !== null && !$user->can($item['capability'])) {
                continue;
            }

            $children = [];

            foreach ($item['children'] as $child) {
                if ($child['capability'] !== null && !$user->can($child['capability'])) {
                    continue;
                }

                $children[] = [
                    'label' => $child['label'],
                    'url' => AdminUrl::to($child['path']),
                    'current' => trim($child['path'], '/') === $currentPath,
                ];
            }

            $isCurrent = trim($item['path'], '/') === $currentPath;
            $childIsCurrent = in_array(true, array_column($children, 'current'), true);

            $visible[] = [
                'label' => $item['label'],
                'url' => AdminUrl::to($item['path']),
                // Only one link per page is marked current: a parent with children leaves that to the child.
                'current' => $isCurrent && $children === [],
                'open' => $isCurrent || $childIsCurrent,
                'children' => $children,
            ];
        }

        return $visible;
    }

    /**
     * Drops anything a filter returned that is not a well-formed entry, so a careless plugin can
     * break its own menu item but never the whole sidebar.
     *
     * @param array<mixed> $items
     * @return list<array{slug: string, label: string, path: string, capability: Capability|null, children: list<array{slug: string, label: string, path: string, capability: Capability|null}>}>
     */
    private function sanitise(array $items): array
    {
        $clean = [];

        foreach ($items as $item) {
            $entry = $this->entry($item);

            if ($entry === null) {
                continue;
            }

            $children = [];

            foreach (is_array($item['children'] ?? null) ? $item['children'] : [] as $child) {
                $childEntry = $this->entry($child);

                if ($childEntry !== null) {
                    $children[] = $childEntry;
                }
            }

            $clean[] = $entry + ['children' => $children];
        }

        return $clean;
    }

    /**
     * @return array{slug: string, label: string, path: string, capability: Capability|null}|null
     */
    private function entry(mixed $item): ?array
    {
        if (
            !is_array($item)
            || !is_string($item['slug'] ?? null) || preg_match('/^[a-z][a-z0-9-]*\z/', $item['slug']) !== 1
            || !is_string($item['label'] ?? null) || $item['label'] === ''
            || !is_string($item['path'] ?? null) || ($item['path'] !== '' && preg_match('#^[A-Za-z0-9_./-]+\z#', $item['path']) !== 1) || str_contains($item['path'], '..')
        ) {
            return null;
        }

        $capability = $item['capability'] ?? null;

        // A missing or unrecognised capability must not make an entry public: it is treated as admin-only.
        if (!$capability instanceof Capability && $capability !== null) {
            $capability = Capability::ManageSite;
        }

        return ['slug' => $item['slug'], 'label' => $item['label'], 'path' => $item['path'], 'capability' => $capability];
    }
}
