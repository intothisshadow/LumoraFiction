<?php

/**
 * Adds an Admin link to the public main menu for people who may use the admin area.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Theme;

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Models\Capability;
use LumoraFiction\Services\AuthService;

/**
 * Core adds the item through the menu filter so every theme shows it without a template change, and a theme never
 * learns who is signed in. The item is left out of the list for everyone else, not hidden, so a guest cannot find
 * it in the page source.
 */
final class StaffNavigation
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function register(HookManager $hooks): void
    {
        // A late priority keeps the staff link after the items other features add.
        $hooks->addFilter('lf_navigation_items', $this->add(...), 100);
    }

    public function add(mixed $items): mixed
    {
        if (!is_array($items) || !$this->auth->can(Capability::AccessAdmin)) {
            return $items;
        }

        $items[] = ['label' => 'Admin', 'url' => AdminUrl::to(), 'current' => false];

        return $items;
    }
}
