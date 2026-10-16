<?php

/**
 * The admin home: a welcome, anything that needs attention, and a summary for administrators.
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

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Admin\AdminView;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuditLogService;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\StoryService;
use LumoraFiction\Services\UserService;

final class DashboardController extends AdminController
{
    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly UserService $users,
        private readonly AuditLogService $audit,
        private readonly Configuration $config,
        private readonly HookManager $hooks,
        private readonly StoryService $stories,
        private readonly string $root,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function index(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::AccessAdmin, '');

        if ($user instanceof Response) {
            return $user;
        }

        $isAdministrator = $user->can(Capability::ManageSite);

        return $this->view->render('dashboard', [
            'user' => $user,
            'notices' => $isAdministrator ? $this->notices($request) : [],
            'summary' => $user->can(Capability::ManageUsers) ? $this->users->summary() : null,
            'writing' => $user->can(Capability::WriteStories) ? $this->stories->countsByState($user->id) : null,
            'awaitingApproval' => $user->can(Capability::ModerateStories) ? $this->stories->countPending($user) : null,
            'reviewUrl' => AdminUrl::to('review'),
            'storiesUrl' => AdminUrl::to('stories'),
            'newStoryUrl' => AdminUrl::to('stories/new'),
            'activity' => $isAdministrator ? $this->audit->recent(8) : [],
        ], $user, 'Dashboard', '', $this->flash->pull($request->cookies));
    }

    /**
     * Things an administrator should look at. The installer folder and debug mode are the two that
     * quietly weaken a live site if forgotten.
     *
     * @return list<array{variant: string, message: string}>
     */
    private function notices(Request $request): array
    {
        $notices = [];

        if (is_dir($this->root . '/install')) {
            $notices[] = ['variant' => 'error', 'message' => 'The installer folder is still on the server. Delete the "install" folder; leaving it there is a security risk.'];
        }

        if ($this->config->get('debug', false) === true) {
            $notices[] = ['variant' => 'warning', 'message' => 'Debug mode is on, so visitors can see technical details when something goes wrong. Turn it off on a live site.'];
        }

        if ((string) $this->config->get('site_url', '') === '') {
            $notices[] = ['variant' => 'error', 'message' => 'No site address is set, so links in emails cannot be created.'];
        }

        if (!$request->isHttps()) {
            $notices[] = ['variant' => 'warning', 'message' => 'This site is not being served over HTTPS. Passwords and sessions travel unprotected; ask your host for a certificate.'];
        }

        $extra = $this->hooks->applyFilters('admin_dashboard_notices', [], $request);

        foreach (is_array($extra) ? $extra : [] as $notice) {
            if (
                is_array($notice)
                && in_array($notice['variant'] ?? null, ['error', 'warning', 'info', 'success'], true)
                && is_string($notice['message'] ?? null)
            ) {
                $notices[] = ['variant' => $notice['variant'], 'message' => $notice['message']];
            }
        }

        return $notices;
    }
}
