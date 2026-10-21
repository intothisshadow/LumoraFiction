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
        TermSuggestController $suggest,
        TaxonomyController $taxonomy,
        StoryController $stories,
        ChapterController $chapters,
        ReviewController $review,
        UsersController $users,
        AppearanceController $appearance,
        SystemInformationController $systemInformation,
        PluginsController $plugins,
        SpamSettingsController $spam,
        SecurityEventsController $securityEvents,
        SecuritySettingsController $security,
    ): void {
        $router->get('/admin', $dashboard->index(...), 'admin.dashboard');
        $router->get('/admin/login', $auth->showLogin(...), 'admin.login');
        $router->post('/admin/login', $auth->login(...));
        $router->post('/admin/logout', $auth->logout(...), 'admin.logout');
        $router->get('/admin/settings/archive', $archive->show(...), 'admin.settings.archive');
        $router->post('/admin/settings/archive', $archive->update(...));
        $router->get('/admin/settings/editor', $editor->show(...), 'admin.settings.editor');
        $router->post('/admin/settings/editor', $editor->update(...));
        $router->get('/admin/settings/security', $security->show(...), 'admin.settings.security');
        $router->post('/admin/settings/security', $security->update(...));
        $router->get('/admin/settings/spam',$spam->show(...), 'admin.settings.spam');
        $router->post('/admin/settings/spam', $spam->update(...));
        $router->post('/admin/settings/spam/resume', $spam->resume(...), 'admin.settings.spam.resume');
        $router->get('/admin/maintenance/security-events', $securityEvents->index(...), 'admin.security-events');
        $router->post('/admin/content/preview', $content->preview(...), 'admin.content.preview');
        $router->post('/admin/content/convert', $content->convert(...), 'admin.content.convert');
        // Before the /admin/taxonomy/{type} pattern below, which would otherwise read "suggest" as a kind.
        $router->get('/admin/taxonomy/suggest', $suggest->suggest(...), 'admin.taxonomy.suggest');
        $router->get('/admin/taxonomy', $taxonomy->overview(...), 'admin.taxonomy');
        $router->post('/admin/taxonomy/required', $taxonomy->saveRequired(...), 'admin.taxonomy.required');
        $router->get('/admin/taxonomy/term/{id}', $taxonomy->showEdit(...), 'admin.taxonomy.term');
        $router->post('/admin/taxonomy/term/{id}', $taxonomy->update(...));
        $router->post('/admin/taxonomy/term/{id}/aliases', $taxonomy->addAlias(...));
        $router->get('/admin/taxonomy/term/{id}/merge', $taxonomy->showMerge(...));
        $router->post('/admin/taxonomy/term/{id}/merge', $taxonomy->merge(...));
        $router->get('/admin/taxonomy/term/{id}/delete', $taxonomy->showDelete(...));
        $router->post('/admin/taxonomy/term/{id}/delete', $taxonomy->delete(...));
        $router->post('/admin/taxonomy/alias/{id}/remove', $taxonomy->removeAlias(...));
        $router->get('/admin/taxonomy/{type}', $taxonomy->list(...), 'admin.taxonomy.list');
        $router->get('/admin/taxonomy/{type}/new', $taxonomy->showCreate(...));
        $router->post('/admin/taxonomy/{type}/new', $taxonomy->create(...));
        // The fixed words "all", "new" and "bulk" come before {id} so they are never read as a story number.
        $router->get('/admin/stories', $stories->index(...), 'admin.stories');
        $router->get('/admin/stories/all', $stories->all(...), 'admin.stories.all');
        $router->get('/admin/stories/new', $stories->showCreate(...), 'admin.stories.new');
        $router->post('/admin/stories/new', $stories->create(...));
        $router->post('/admin/stories/bulk', $stories->bulk(...), 'admin.stories.bulk');
        $router->get('/admin/stories/{id}', $stories->showEdit(...), 'admin.stories.edit');
        $router->post('/admin/stories/{id}', $stories->update(...));
        $router->get('/admin/stories/{id}/delete', $stories->showDelete(...));
        $router->post('/admin/stories/{id}/delete', $stories->delete(...));
        $router->post('/admin/stories/{id}/publish', $stories->publish(...));
        $router->post('/admin/stories/{id}/unpublish', $stories->unpublish(...));
        $router->get('/admin/stories/{id}/preview', $stories->preview(...), 'admin.stories.preview');
        $router->get('/admin/stories/{id}/chapters', $chapters->index(...), 'admin.chapters');
        $router->post('/admin/stories/{id}/chapters/move', $chapters->move(...));
        $router->get('/admin/stories/{id}/chapters/new', $chapters->showCreate(...));
        $router->post('/admin/stories/{id}/chapters/new', $chapters->create(...));
        $router->get('/admin/chapters/{id}', $chapters->showEdit(...), 'admin.chapters.edit');
        $router->post('/admin/chapters/{id}', $chapters->update(...));
        $router->get('/admin/chapters/{id}/delete', $chapters->showDelete(...));
        $router->post('/admin/chapters/{id}/delete', $chapters->delete(...));
        $router->post('/admin/chapters/{id}/publish', $chapters->publish(...));
        $router->post('/admin/chapters/{id}/unpublish', $chapters->unpublish(...));
        $router->get('/admin/chapters/{id}/preview', $chapters->preview(...), 'admin.chapters.preview');
        $router->get('/admin/review', $review->index(...), 'admin.review');
        $router->post('/admin/review/{id}/approve', $review->approve(...));
        $router->get('/admin/review/{id}/reject', $review->showReject(...));
        $router->post('/admin/review/{id}/reject', $review->reject(...));
        $router->get('/admin/users', $users->index(...), 'admin.users');
        $router->get('/admin/users/{id}', $users->show(...), 'admin.users.show');
        $router->post('/admin/users/{id}/role', $users->changeRole(...));
        $router->post('/admin/users/{id}/publishing', $users->setPublishing(...));
        $router->get('/admin/users/{id}/suspend', $users->showSuspend(...));
        $router->post('/admin/users/{id}/suspend', $users->suspend(...));
        $router->get('/admin/users/{id}/reinstate', $users->showReinstate(...));
        $router->post('/admin/users/{id}/reinstate', $users->reinstate(...));
        $router->get('/admin/appearance', $appearance->themes(...), 'admin.appearance');
        $router->post('/admin/appearance/activate', $appearance->activate(...), 'admin.appearance.activate');
        $router->get('/admin/appearance/options', $appearance->showOptions(...), 'admin.appearance.options');
        $router->post('/admin/appearance/options', $appearance->saveOptions(...));
        $router->get('/admin/maintenance/system-information',$systemInformation->show(...), 'admin.system-information');
        $router->get('/admin/maintenance/system-information.json', $systemInformation->download(...), 'admin.system-information.download');
        $router->get('/admin/maintenance/plugins', $plugins->index(...), 'admin.plugins');
        $router->post('/admin/maintenance/plugins/activate', $plugins->activate(...), 'admin.plugins.activate');
        $router->post('/admin/maintenance/plugins/deactivate', $plugins->deactivate(...), 'admin.plugins.deactivate');
    }
}
