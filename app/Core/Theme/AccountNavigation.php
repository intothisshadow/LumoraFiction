<?php

/**
 * Adds the sign-in, registration and account links to the main menu.
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

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Services\AuthService;

/**
 * Added through the menu filter so every theme shows the links without a template change, and a theme never learns
 * who is signed in. The links run before the staff link, so the admin entry stays last.
 */
final class AccountNavigation
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly Configuration $config,
    ) {
    }

    public function register(HookManager $hooks): void
    {
        $hooks->addFilter('lf_navigation_items', $this->add(...), 90);
    }

    public function add(mixed $items): mixed
    {
        if (!is_array($items)) {
            return $items;
        }

        if ($this->auth->check()) {
            $items[] = ['label' => 'Account', 'url' => PublicUrl::account('profile'), 'current' => false];

            return $items;
        }

        $items[] = ['label' => 'Sign in', 'url' => PublicUrl::account('sign-in'), 'current' => false];

        if ($this->config->setting('registration.enabled', true) === true) {
            $items[] = ['label' => 'Register', 'url' => PublicUrl::account('register'), 'current' => false];
        }

        return $items;
    }
}
