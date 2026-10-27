<?php

/**
 * Visitor Stats: a local, privacy-respecting view counter for stories.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

/**
 * Plugin Name: Visitor Stats
 * Version: 1.0.0
 * Author: Ariane
 * Description: Counts story views on this site alone, with no cookies and no personal data, and shows them on the dashboard, a Visitor stats screen and each story's own screen. Off until switched on.
 * Requires PHP: 8.2
 * Requires at least: 1.1.0
 */

declare(strict_types=1);

use LumoraFiction\Models\Capability;
use LumoraFiction\Plugins\VisitorStats\GeoIp;
use LumoraFiction\Plugins\VisitorStats\Panels;
use LumoraFiction\Plugins\VisitorStats\Screen;
use LumoraFiction\Plugins\VisitorStats\Store;
use LumoraFiction\Plugins\VisitorStats\Tracker;
use LumoraFiction\Plugins\VisitorStats\UserAgentParser;

require_once __DIR__ . '/src/UserAgentParser.php';
require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/GeoIp.php';
require_once __DIR__ . '/src/Tracker.php';
require_once __DIR__ . '/src/View.php';
require_once __DIR__ . '/src/Panels.php';
require_once __DIR__ . '/src/Screen.php';

$store = new Store($kernel->database);
$tracker = new Tracker($kernel->config, $store, new GeoIp($kernel->root . '/storage/visitor-stats'), new UserAgentParser());
$panels = new Panels($store, $kernel->database, $tracker);

add_action('story_viewed', $tracker->handle(...));

add_filter('admin_menu', static function (array $items): array {
    $entry = ['slug' => 'visitor-stats', 'label' => 'Visitor stats', 'path' => Screen::PATH, 'capability' => Capability::ManageSite, 'children' => []];
    $at = count($items);

    foreach ($items as $index => $item) {
        if (($item['slug'] ?? '') === 'appearance') {
            $at = $index;

            break;
        }
    }

    array_splice($items, $at, 0, [$entry]);

    return $items;
});

// Seen without opening the screen, because nothing else tells an administrator the country data has aged.
add_filter('admin_alerts', static function (array $alerts) use ($kernel, $tracker): array {
    if ($tracker->enabled() && (new GeoIp($kernel->root . '/storage/visitor-stats'))->isStale()) {
        $alerts[] = new \LumoraFiction\Models\SiteAlert(
            'visitor-stats.geo-stale',
            \LumoraFiction\Models\AlertSeverity::Warning,
            'The country data used by Visitor Stats is more than six months old. Download a fresh copy from MaxMind and import it again.',
            '',
            Screen::PATH,
        );
    }

    return $alerts;
});

add_filter('admin_dashboard_cards', static function (array $cards, \LumoraFiction\Models\User $user) use ($panels): array {
    $card = $panels->dashboardCard($user);

    if ($card !== null) {
        $cards[] = $card;
    }

    return $cards;
});

add_filter('admin_story_panels', static function (array $found, \LumoraFiction\Models\Story $story, \LumoraFiction\Models\User $user) use ($panels): array {
    $panel = $panels->storyPanel($story, $user);

    if ($panel !== null) {
        $found[] = $panel;
    }

    return $found;
});

add_action('routes_register', static function (\LumoraFiction\Core\Http\Router $router) use ($kernel, $store, $tracker, $panels): void {
    if ($kernel->admin === null) {
        return;
    }

    $screen = new Screen(
        $kernel->auth,
        $kernel->admin,
        new \LumoraFiction\Core\Http\FlashMessages($kernel->sessions),
        $kernel->config,
        $store,
        new GeoIp($kernel->root . '/storage/visitor-stats'),
        $tracker,
        $panels,
        $kernel->audit,
        (string) (require $kernel->root . '/version.php')['version'],
    );
    $base = '/admin/' . Screen::PATH;

    $router->get($base, $screen->show(...), 'admin.visitor-stats');
    $router->post($base . '/settings', $screen->saveSettings(...));
    $router->post($base . '/geo/incoming', $screen->importIncoming(...));
    $router->post($base . '/geo/path', $screen->importPath(...));
    $router->post($base . '/geo/upload', $screen->upload(...));
    $router->post($base . '/geo/continue', $screen->continueImport(...));
    $router->post($base . '/geo/cancel', $screen->cancelImport(...));
    $router->post($base . '/geo/remove', $screen->removeGeo(...));
});
