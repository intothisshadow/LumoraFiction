<?php

/**
 * Maintenance › Security events: what the protections blocked.
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
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Models\Capability;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\SecurityEventLog;

final class SecurityEventsController extends AdminController
{
    private const PATH = 'maintenance/security-events';

    private const PER_PAGE = 50;

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly SecurityEventLog $events,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function index(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        // Anything that is not a known event name is no filter, so the value is never part of a query.
        $event = $request->queryValue('event') ?? '';
        $event = isset(SecurityEventLog::LABELS[$event]) ? $event : '';

        $total = $this->events->count($event);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $requested = $request->queryValue('page');
        $page = min($pages, max(1, $requested !== null && ctype_digit($requested) ? (int) min((int) $requested, 100000) : 1));

        return $this->view->render('security-events', [
            'events' => $this->events->recent(self::PER_PAGE, ($page - 1) * self::PER_PAGE, $event),
            'labels' => SecurityEventLog::LABELS,
            'event' => $event,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'retentionDays' => $this->events->retentionDays(),
            'listUrl' => AdminUrl::to(self::PATH),
            'settingsUrl' => AdminUrl::to('settings/spam'),
        ], $user, 'Security events', self::PATH, $this->flash->pull($request->cookies));
    }
}
