<?php

/**
 * The Notifications screen: a writer's or reviewer's inbox about stories.
 *
 * @package LumoraFiction
 * @subpackage Controllers
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
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\NotificationService;

/**
 * Only the signed-in person's own notifications are ever listed or changed; the service puts that limit in the query.
 * These are about stories, so Editors and Authors see them and the site-health alerts, which are only for
 * Administrators, are a separate list.
 */
final class NotificationsController extends WriterController
{
    private const PATH = 'notifications';

    private const PER_PAGE = 25;

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly NotificationService $notifications,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function index(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $unreadOnly = $request->queryValue('show') === 'unread';
        $total = $this->notifications->count($user, $unreadOnly);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $requested = $request->queryValue('page');
        $page = min($pages, max(1, $requested !== null && ctype_digit($requested) ? (int) min((int) $requested, 100000) : 1));

        return $this->view->render('notifications', [
            'notifications' => $this->notifications->forUser($user, $unreadOnly, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'unreadOnly' => $unreadOnly,
            'unread' => $this->notifications->countUnread($user),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
            'listUrl' => AdminUrl::to(self::PATH),
            'readUrl' => AdminUrl::to(self::PATH . '/read'),
            'readAllUrl' => AdminUrl::to(self::PATH . '/read-all'),
        ], $user, 'Notifications', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $params
     */
    public function read(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            $this->flash->add('error', self::STALE_FORM);
        } else {
            $id = preg_match('/^[1-9][0-9]{0,9}\z/', $request->input('id') ?? '') === 1 ? (int) $request->input('id') : 0;
            $this->notifications->markRead($user, $id);
        }

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function readAll(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            $this->flash->add('error', self::STALE_FORM);
        } else {
            $this->notifications->markAllRead($user);
            $this->flash->add('success', 'Everything is marked as read.');
        }

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }
}
