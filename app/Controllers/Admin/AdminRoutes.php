<?php

/**
 * Registers the admin area's addresses.
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

namespace LumoraFiction\Controllers\Admin;

use LumoraFiction\Core\Http\Router;

/**
 * Sign-out only answers POST. Any other method gets a 405, so following a link, or a hostile page
 * embedding an image that points here, cannot end someone's session.
 */
final class AdminRoutes
{
    public static function register(
        Router $router,
        AuthController $auth,
        DashboardController $dashboard,
        ArchiveSettingsController $archive,
        EditorSettingsController $editor,
        ContentEditorController $content,
        SystemInformationController $systemInformation,
    ): void {
        $router->get('/admin', $dashboard->index(...), 'admin.dashboard');
        $router->get('/admin/login', $auth->showLogin(...), 'admin.login');
        $router->post('/admin/login', $auth->login(...));
        $router->post('/admin/logout', $auth->logout(...), 'admin.logout');
        $router->get('/admin/settings/archive', $archive->show(...), 'admin.settings.archive');
        $router->post('/admin/settings/archive', $archive->update(...));
        $router->get('/admin/settings/editor', $editor->show(...), 'admin.settings.editor');
        $router->post('/admin/settings/editor', $editor->update(...));
        $router->post('/admin/content/preview', $content->preview(...), 'admin.content.preview');
        $router->post('/admin/content/convert', $content->convert(...), 'admin.content.convert');
        $router->get('/admin/maintenance/system-information', $systemInformation->show(...), 'admin.system-information');
        $router->get('/admin/maintenance/system-information.json', $systemInformation->download(...), 'admin.system-information.download');
    }
}
