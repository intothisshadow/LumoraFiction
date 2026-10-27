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
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\SiteAlert;
use LumoraFiction\Models\User;
use LumoraFiction\Services\ActivityDescriber;
use LumoraFiction\Services\AuditLogService;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\NewsService;
use LumoraFiction\Services\SiteAlerts;
use LumoraFiction\Services\StoryService;
use LumoraFiction\Services\UserService;

final class DashboardController extends AdminController
{
    /** The dashboard shows only the most serious few; the Alerts screen has the rest. */
    private const ALERTS_SHOWN = 5;

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly UserService $users,
        private readonly AuditLogService $audit,
        private readonly SiteAlerts $alerts,
        private readonly StoryService $stories,
        private readonly NewsService $news,
        private readonly ActivityDescriber $describer = new ActivityDescriber(),
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
            'notices' => array_map(
                static fn (SiteAlert $alert): array => ['variant' => $alert->severity->variant(), 'message' => $alert->message],
                $this->alerts->top($user, self::ALERTS_SHOWN),
            ),
            'alertTotal' => count($this->alerts->all($user)),
            'alertsUrl' => AdminUrl::to('maintenance/alerts'),
            'summary' => $user->can(Capability::ManageUsers) ? $this->users->summary() : null,
            'writing' => $user->can(Capability::WriteStories) ? $this->stories->countsByState($user->id) : null,
            'awaitingApproval' => $user->can(Capability::ModerateStories) ? $this->stories->countPending($user) : null,
            'reviewUrl' => AdminUrl::to('review'),
            'newsCounts' => $user->can(Capability::PublishNews) ? $this->news->countsByState() : null,
            'newsUrl' => AdminUrl::to('news'),
            'newNewsUrl' => AdminUrl::to('news/new'),
            'storiesUrl' => AdminUrl::to('stories'),
            'newStoryUrl' => AdminUrl::to('stories/new'),
            'activity' => $isAdministrator ? $this->activity() : [],
            'cards' => $this->pluginCards($user),
        ], $user, 'Dashboard', '', $this->flash->pull($request->cookies));
    }

    /**
     * The latest entries of the activity log as sentences a person can read.
     *
     * @return list<array{actor: string, text: string, at: \DateTimeImmutable}>
     */
    private function activity(): array
    {
        return array_map(fn (array $entry): array => [
            'actor' => $entry['actor'] ?? 'The system',
            'text' => $this->describer->describe($entry),
            'at' => $entry['at'],
        ], $this->audit->recent(8));
    }

    /**
     * Cards a plugin adds below the core ones. Anything that is not a card with a title and markup is dropped, so a
     * careless plugin cannot break the screen.
     *
     * @return list<array{title: string, html: string}>
     */
    private function pluginCards(User $user): array
    {
        $cards = [];

        foreach ((array) apply_filters('admin_dashboard_cards', [], $user) as $card) {
            if (is_array($card) && is_string($card['title'] ?? null) && is_string($card['html'] ?? null)) {
                $cards[] = ['title' => $card['title'], 'html' => $card['html']];
            }
        }

        return $cards;
    }
}
