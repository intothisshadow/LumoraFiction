<?php

/**
 * The Users screen: find an account, change its role, suspend or reinstate it, and set an author's publishing rights.
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
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\PublishingOverride;
use LumoraFiction\Models\User;
use LumoraFiction\Models\UserRole;
use LumoraFiction\Models\UserStatus;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\StoryService;
use LumoraFiction\Services\UserAdminException;
use LumoraFiction\Services\UserAdminService;
use LumoraFiction\Services\UserService;

/**
 * Every action is refused by the service unless the person may manage users, and the screen never shows or accepts a
 * login name: people are found and named by their display name and email address only. Each change asks for its own
 * token, and the two that take someone's access away or give it back have a page of their own.
 */
final class UsersController extends WriterController
{
    private const PATH = 'users';

    private const PER_PAGE = 25;

    private const TOKEN = 'admin_users';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly UserAdminService $admin,
        private readonly UserService $users,
        private readonly StoryService $stories,
        private readonly Configuration $config,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function index(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageUsers, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $filters = [
            'search' => mb_substr(trim($request->queryValue('q') ?? ''), 0, 100),
            'role' => UserRole::tryFrom($request->queryValue('role') ?? '')?->value ?? '',
            'status' => UserStatus::tryFrom($request->queryValue('status') ?? '')?->value ?? '',
        ];

        $total = $this->admin->count($user, $filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $requested = $request->queryValue('page');
        $page = min($pages, max(1, $requested !== null && ctype_digit($requested) ? (int) min((int) $requested, 100000) : 1));

        return $this->view->render('users-list', [
            'people' => $this->admin->list($user, $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'filters' => $filters,
            'roles' => UserRole::cases(),
            'statuses' => UserStatus::cases(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'listUrl' => AdminUrl::to(self::PATH),
            'personUrl' => AdminUrl::to(self::PATH),
            'selfId' => $user->id,
        ], $user, 'Users', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $params
     */
    public function show(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageUsers, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $person = $this->person($params);

        return $person === null ? $this->missing('That account does not exist.', self::PATH) : $this->personPage($request, $user, $person);
    }

    /**
     * @param array<string, string> $params
     */
    public function changeRole(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageUsers, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $person = $this->person($params);

        if ($person === null) {
            return $this->missing('That account does not exist.', self::PATH);
        }

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->back($person, 'error', self::STALE_FORM);
        }

        $role = UserRole::tryFrom($request->input('role') ?? '');

        if ($role === null) {
            return $this->back($person, 'error', 'Choose one of the roles listed.');
        }

        try {
            $this->admin->changeRole($user, $person->id, $role, $request->input('reason') ?? '', $request->ip());
        } catch (UserAdminException $exception) {
            return $this->back($person, 'error', $exception->getMessage());
        }

        return $this->back($person, 'success', $person->displayName . ' is now ' . $this->article($role) . ' ' . strtolower($role->label()) . '.');
    }

    /**
     * @param array<string, string> $params
     */
    public function setPublishing(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageUsers, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $person = $this->person($params);

        if ($person === null) {
            return $this->missing('That account does not exist.', self::PATH);
        }

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->back($person, 'error', self::STALE_FORM);
        }

        $choice = $request->input('publishing') ?? '';
        $override = PublishingOverride::tryFrom($choice);

        // An empty choice means "follow the site setting"; anything else that is not a known value is refused.
        if ($choice !== '' && $override === null) {
            return $this->back($person, 'error', 'Choose one of the options listed.');
        }

        try {
            $this->admin->setPublishingRights($user, $person->id, $override, $request->ip());
        } catch (UserAdminException $exception) {
            return $this->back($person, 'error', $exception->getMessage());
        }

        return $this->back($person, 'success', 'Publishing rights saved for ' . $person->displayName . '.');
    }

    /**
     * @param array<string, string> $params
     */
    public function showSuspend(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageUsers, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $person = $this->person($params);

        if ($person === null) {
            return $this->missing('That account does not exist.', self::PATH);
        }

        if ($person->id === $user->id || $person->status === UserStatus::Suspended) {
            return $this->back($person, 'error', $person->id === $user->id ? 'You cannot suspend your own account.' : 'That account is already suspended.');
        }

        return $this->suspendPage($request, $user, $person, '', false, [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function suspend(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageUsers, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $person = $this->person($params);

        if ($person === null) {
            return $this->missing('That account does not exist.', self::PATH);
        }

        $reason = $request->input('reason') ?? '';
        $unpublish = $request->checked('unpublish');

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->suspendPage($request, $user, $person, $reason, $unpublish, ['form' => self::STALE_FORM], 400);
        }

        try {
            $count = $this->admin->suspend($user, $person->id, $unpublish, $reason, $request->ip());
        } catch (UserAdminException $exception) {
            return $this->suspendPage($request, $user, $person, $reason, $unpublish, ['form' => $exception->getMessage()], 422);
        }

        $message = $person->displayName . ' is suspended and signed out.';

        if ($unpublish) {
            $message .= ' ' . $count . ($count === 1 ? ' story was' : ' stories were') . ' taken out of view.';
        }

        return $this->back($person, 'success', $message);
    }

    /**
     * @param array<string, string> $params
     */
    public function showReinstate(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageUsers, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $person = $this->person($params);

        if ($person === null) {
            return $this->missing('That account does not exist.', self::PATH);
        }

        if ($person->status !== UserStatus::Suspended) {
            return $this->back($person, 'error', 'That account is not suspended.');
        }

        return $this->reinstatePage($request, $user, $person, '', [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function reinstate(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageUsers, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $person = $this->person($params);

        if ($person === null) {
            return $this->missing('That account does not exist.', self::PATH);
        }

        $reason = $request->input('reason') ?? '';

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->reinstatePage($request, $user, $person, $reason, ['form' => self::STALE_FORM], 400);
        }

        try {
            $changed = $this->admin->reinstate($user, $person->id, $reason, $request->ip());
        } catch (UserAdminException $exception) {
            return $this->reinstatePage($request, $user, $person, $reason, ['form' => $exception->getMessage()], 422);
        }

        return $this->back($person, 'success', $changed->status === UserStatus::Pending
            ? $person->displayName . ' can sign in again once they confirm their email address.'
            : $person->displayName . ' can sign in again.');
    }

    /**
     * @param array<string, string> $params
     */
    private function person(array $params): ?User
    {
        $id = $this->idFrom($params);

        return $id === 0 ? null : $this->users->findById($id);
    }

    private function personPage(Request $request, User $user, User $person): Response
    {
        $counts = $this->stories->countsByState($person->id);
        $published = $counts[PublicationState::Published->value] ?? 0;

        return $this->view->render('user-detail', [
            'person' => $person,
            'isSelf' => $person->id === $user->id,
            'counts' => $counts,
            'states' => PublicationState::cases(),
            // The author page only answers once something is public, so no dead link is offered before that.
            'publicUrl' => $published > 0 ? PublicUrl::author($person->slug) : '',
            'roles' => UserRole::cases(),
            'overrides' => PublishingOverride::cases(),
            'siteHoldsFirst' => $this->config->setting('publishing.hold_first_story') === true,
            'csrfToken' => Csrf::token(self::TOKEN),
            'roleUrl' => AdminUrl::to(self::PATH . '/' . $person->id . '/role'),
            'publishingUrl' => AdminUrl::to(self::PATH . '/' . $person->id . '/publishing'),
            'suspendUrl' => AdminUrl::to(self::PATH . '/' . $person->id . '/suspend'),
            'reinstateUrl' => AdminUrl::to(self::PATH . '/' . $person->id . '/reinstate'),
            'listUrl' => AdminUrl::to(self::PATH),
        ], $user, $person->displayName, self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $errors
     */
    private function suspendPage(Request $request, User $user, User $person, string $reason, bool $unpublish, array $errors, int $status): Response
    {
        $counts = $this->stories->countsByState($person->id);

        return $this->view->render('user-suspend', [
            'person' => $person,
            'reason' => $reason,
            'unpublish' => $unpublish,
            'liveStories' => ($counts[PublicationState::Published->value] ?? 0) + ($counts[PublicationState::Pending->value] ?? 0),
            'limit' => StoryService::REASON_MAX,
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH . '/' . $person->id . '/suspend'),
            'backUrl' => AdminUrl::to(self::PATH . '/' . $person->id),
        ], $user, 'Suspend ' . $person->displayName, self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @param array<string, string> $errors
     */
    private function reinstatePage(Request $request, User $user, User $person, string $reason, array $errors, int $status): Response
    {
        return $this->view->render('user-reinstate', [
            'person' => $person,
            'needsConfirmation' => $this->admin->statusAfterReinstating($person) === UserStatus::Pending,
            'reason' => $reason,
            'limit' => StoryService::REASON_MAX,
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH . '/' . $person->id . '/reinstate'),
            'backUrl' => AdminUrl::to(self::PATH . '/' . $person->id),
        ], $user, 'Reinstate ' . $person->displayName, self::PATH, $this->flash->pull($request->cookies), $status);
    }

    private function back(User $person, string $kind, string $message): Response
    {
        $this->flash->add($kind, $message);

        return Response::redirect(AdminUrl::to(self::PATH . '/' . $person->id), 303);
    }

    private function article(UserRole $role): string
    {
        return $role === UserRole::Administrator || $role === UserRole::Editor ? 'an' : 'a';
    }
}
