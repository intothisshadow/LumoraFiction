<?php

/**
 * eFiction Importer: brings the authors, stories and chapters of an eFiction 3.5 archive into Lumora Fiction.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

/**
 * Plugin Name: eFiction Importer
 * Version: 1.0.0
 * Author: Ariane
 * Description: Reads an eFiction 3.5 database and brings its authors, stories, chapters and classification across, with a preview, a report and a way to undo it.
 * Requires PHP: 8.3
 * Requires at least: 1.4.0
 */

declare(strict_types=1);

use LumoraFiction\Core\Content\HtmlSanitizer;
use LumoraFiction\Models\Capability;
use LumoraFiction\Plugins\EfictionImporter\Connection;
use LumoraFiction\Plugins\EfictionImporter\Importer;
use LumoraFiction\Plugins\EfictionImporter\Redirects;
use LumoraFiction\Plugins\EfictionImporter\Screen;
use LumoraFiction\Plugins\EfictionImporter\Tracker;
use LumoraFiction\Services\NewsService;

foreach (['SourceException', 'Source', 'ConfigReader', 'FolderFinder', 'Text', 'ChapterText', 'Report', 'Tracker', 'Survey', 'Mapping', 'Connection', 'Importer', 'Redirects', 'Screen'] as $class) {
    require_once __DIR__ . '/src/' . $class . '.php';
}

add_filter('admin_menu', static function (array $items): array {
    foreach ($items as &$item) {
        if (($item['slug'] ?? '') === 'plugins') {
            $item['children'][] = ['slug' => 'plugins-efiction-importer', 'label' => 'eFiction importer', 'path' => Screen::PATH, 'capability' => Capability::ManageSite];
        }
    }

    unset($item);

    return $items;
});

add_action('routes_register', static function (\LumoraFiction\Core\Http\Router $router) use ($kernel): void {
    if ($kernel->admin === null || $kernel->stories === null || $kernel->chapters === null || $kernel->taxonomy === null || $kernel->pages === null) {
        return;
    }

    $tracker = new Tracker($kernel->database);
    $sanitizer = new HtmlSanitizer();
    $news = new NewsService($kernel->database, $kernel->audit, $kernel->hooks, $kernel->newsImages);
    $importer = new Importer($kernel->database, $tracker, $kernel->users, $kernel->stories, $kernel->chapters, $kernel->taxonomy, $news, $kernel->pages, $sanitizer, $kernel->audit, $kernel->hooks, comments: $kernel->comments);
    $root = dirname(__DIR__, 3);
    $screen = new Screen(
        $kernel->auth,
        $kernel->admin,
        new \LumoraFiction\Core\Http\FlashMessages($kernel->sessions),
        new Connection($kernel->sessions),
        $kernel->database,
        $tracker,
        $importer,
        $kernel->taxonomy,
        $kernel->audit,
        $kernel->config,
        $root,
        [
            'host' => (string) $kernel->config->get('db_host', 'localhost'),
            'port' => (int) $kernel->config->get('db_port', 3306),
            'name' => (string) $kernel->config->get('db_name', ''),
            'prefix' => $kernel->database->prefix(),
        ],
        (string) $kernel->config->get('version', ''),
    );

    $base = '/admin/' . Screen::PATH;
    $router->get($base, $screen->show(...), 'admin.efiction-importer');
    $router->post($base . '/connect', $screen->connect(...));
    $router->post($base . '/detect', $screen->detect(...));
    $router->post($base . '/discover', $screen->discover(...));
    $router->post($base . '/forget', $screen->disconnect(...));
    $router->get($base . '/preview', $screen->preview(...));
    $router->post($base . '/start', $screen->start(...));
    $router->get($base . '/progress/{id}', $screen->progress(...));
    $router->post($base . '/step/{id}', $screen->step(...));
    $router->post($base . '/stop/{id}', $screen->stop(...));
    $router->get($base . '/report/{id}', $screen->report(...));
    $router->post($base . '/remove', $screen->remove(...));
    $router->post($base . '/redirects', $screen->redirects(...));

    // The old addresses exist only while the switch is on, so a site that never imported owns no extra routes.
    if ($kernel->config->setting(Screen::REDIRECT_SETTING, false) === true && $tracker->exists()) {
        $redirects = new Redirects($kernel->database, $tracker, $kernel->stories, $kernel->chapters, $kernel->users, $kernel->themes);
        $router->get('/viewstory.php', $redirects->story(...));
        $router->get('/viewuser.php', $redirects->user(...));
        $router->get('/viewpage.php', $redirects->page(...));
    }
});
