<?php

/**
 * Sweep: a cleanup tool for long-running archives.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

/**
 * Plugin Name: Sweep
 * Version: 1.0.0
 * Author: Ariane
 * Description: Shows what a long-running archive can clean out (old chapter versions, expired trash and links, accounts that never confirmed, unused tags and fandoms), previews it, and removes it on confirmation. Off until switched on.
 * Requires PHP: 8.3
 * Requires at least: 1.3.0
 */

declare(strict_types=1);

use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Plugins\Sweep\Reminder;
use LumoraFiction\Plugins\Sweep\Screen;
use LumoraFiction\Plugins\Sweep\Sweeper;
use LumoraFiction\Plugins\Sweep\SweepSettings;

require_once __DIR__ . '/src/SweepSettings.php';
require_once __DIR__ . '/src/Sweeper.php';
require_once __DIR__ . '/src/Reminder.php';
require_once __DIR__ . '/src/Screen.php';

// The shared services are what removes things, so cover files, links and hooks are handled as everywhere else.
if ($kernel->stories === null || $kernel->chapters === null || $kernel->taxonomy === null || $kernel->notifications === null || $kernel->accountDeletion === null) {
    throw new RuntimeException('Sweep needs a newer Lumora Fiction. Update the site, then turn the plugin on again.');
}

$settings = new SweepSettings($kernel->config, $kernel->database, $kernel->audit);
$sweeper = new Sweeper(
    $kernel->database,
    $kernel->config,
    $kernel->stories,
    $kernel->chapters,
    $kernel->taxonomy,
    $kernel->accountDeletion,
    $kernel->users,
    $kernel->audit,
    $settings,
);

add_filter('admin_menu', static function (array $items): array {
    foreach ($items as &$item) {
        if (($item['slug'] ?? '') === 'maintenance') {
            $entry = ['slug' => 'maintenance-sweep', 'label' => 'Sweep', 'path' => Screen::PATH, 'capability' => Capability::ManageSite];
            $at = count($item['children'] ?? []);

            foreach ($item['children'] ?? [] as $index => $child) {
                if (($child['slug'] ?? '') === 'maintenance-backups') {
                    $at = $index + 1;

                    break;
                }
            }

            // Straight after Backups; at the end if that entry is not there.
            array_splice($item['children'], $at, 0, [$entry]);
        }
    }

    unset($item);

    return $items;
});

// The dashboard is what an Administrator opens first, so the weekly count is taken there; Reminder rate-limits itself.
add_filter('admin_dashboard_cards', static function (array $cards, User $user) use ($kernel, $settings, $sweeper): array {
    if ($user->can(Capability::ManageSite) && $kernel->notifications !== null) {
        (new Reminder($kernel->config, $kernel->database, $kernel->notifications, $sweeper, $settings))->checkIfDue();
    }

    return $cards;
});

add_action('routes_register', static function (\LumoraFiction\Core\Http\Router $router) use ($kernel, $settings, $sweeper): void {
    if ($kernel->admin === null) {
        return;
    }

    $screen = new Screen($kernel->auth, $kernel->admin, new \LumoraFiction\Core\Http\FlashMessages($kernel->sessions), $sweeper, $settings);

    $router->get('/admin/' . Screen::PATH, $screen->show(...), 'admin.sweep');
    $router->post('/admin/' . Screen::PATH . '/preview', $screen->preview(...));
    $router->post('/admin/' . Screen::PATH . '/run', $screen->run(...));
    $router->post('/admin/' . Screen::PATH . '/settings', $screen->saveSettings(...));
});
