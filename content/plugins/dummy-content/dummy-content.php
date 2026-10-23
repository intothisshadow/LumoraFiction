<?php

/**
 * Dummy Content: placeholder users, stories and chapters for testing on a development site.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

/**
 * Plugin Name: Dummy Content
 * Version: 1.0.0
 * Author: Ariane
 * Description: Makes placeholder users, stories and chapters for testing, and removes them again. Development sites only.
 * Requires PHP: 8.2
 * Requires at least: 0.1.0
 */

declare(strict_types=1);

use LumoraFiction\Models\Capability;
use LumoraFiction\Plugins\DummyContent\Generator;
use LumoraFiction\Plugins\DummyContent\Screen;
use LumoraFiction\Plugins\DummyContent\Tracker;

require_once __DIR__ . '/src/Corpus.php';
require_once __DIR__ . '/src/Options.php';
require_once __DIR__ . '/src/Tracker.php';
require_once __DIR__ . '/src/Generator.php';
require_once __DIR__ . '/src/Screen.php';

// Refusing to load, rather than hiding the screen, puts the reason on the Plugins screen and the dashboard.
if (!Generator::allowedIn((string) $kernel->config->get('environment', 'production'))) {
    throw new RuntimeException('Dummy Content only runs on a development site. Set "environment" to "development" in config/config.php to use it, and never do that on a live site.');
}

add_filter('admin_menu', static function (array $items): array {
    foreach ($items as &$item) {
        if (($item['slug'] ?? '') === 'maintenance') {
            $item['children'][] = ['slug' => 'maintenance-dummy-content', 'label' => 'Dummy content', 'path' => Screen::PATH, 'capability' => Capability::ManageSite];
        }
    }

    unset($item);

    return $items;
});

// A reminder on the Alerts screen while placeholder content is on the site, so it is not forgotten there.
add_filter('admin_alerts', static function (array $alerts) use ($kernel): array {
    try {
        $made = array_sum((new Tracker($kernel->database))->counts());
    } catch (\Throwable) {
        // The list of made items is created on first use, so a site that never used the plugin has none yet.
        return $alerts;
    }

    if ($made > 0) {
        $alerts[] = new \LumoraFiction\Models\SiteAlert(
            'dummy-content.present',
            \LumoraFiction\Models\AlertSeverity::Info,
            'Placeholder content from the Dummy Content plugin is on this site. Remove it before the site is used for real.',
            '',
            Screen::PATH,
        );
    }

    return $alerts;
});

add_action('routes_register', static function (\LumoraFiction\Core\Http\Router $router) use ($kernel): void {
    if ($kernel->admin === null) {
        return;
    }

    $tracker = new Tracker($kernel->database);
    $generator = new Generator(
        $kernel->database,
        new \LumoraFiction\Services\TaxonomyService($kernel->database, $kernel->config, $kernel->audit),
        $kernel->audit,
        $tracker,
        (string) $kernel->config->get('environment', 'production'),
    );
    $screen = new Screen($kernel->auth, $kernel->admin, new \LumoraFiction\Core\Http\FlashMessages($kernel->sessions), $generator, $tracker);

    $router->get('/admin/' . Screen::PATH, $screen->show(...), 'admin.dummy-content');
    $router->post('/admin/' . Screen::PATH . '/generate', $screen->generate(...));
    $router->post('/admin/' . Screen::PATH . '/remove', $screen->remove(...));
});
