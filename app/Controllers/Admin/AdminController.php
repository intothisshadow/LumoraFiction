<?php

/**
 * Base class for admin screens: decides who may open one.
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
use LumoraFiction\Core\Admin\BulkResult;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Core\Security\PublishingPolicy;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;

/**
 * Every screen asks for its own capability here, on the server. Hiding a menu entry is only a
 * convenience; typing the address of a screen must be refused just as firmly.
 */
abstract class AdminController
{
    public function __construct(
        protected readonly AuthService $auth,
        protected readonly AdminView $view,
        protected readonly FlashMessages $flash,
    ) {
    }

    /**
     * Returns the signed-in user when they may open the screen, or the response that refuses them:
     * a redirect to the sign-in page for a guest, or a 403 for someone who is not allowed.
     */
    protected function guard(Request $request, Capability $capability, string $currentPath): User|Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return Response::redirect(AdminUrl::to('login'));
        }

        if (!$user->can(Capability::AccessAdmin)) {
            return $this->forbidden($user, $request, false, $currentPath);
        }

        if (!$user->can($capability)) {
            return $this->forbidden($user, $request, true, $currentPath);
        }

        return $user;
    }

    /**
     * Whether a form post carries a valid, unused token for the action.
     */
    protected function validToken(Request $request, string $action): bool
    {
        return Csrf::verify($action, $request->input('csrf_token'), $request->cookies);
    }

    /**
     * Reads a bulk request the same way on every screen: a valid token, one of the actions the screen offers, no more than a
     * page of ticked items and at least one. A request the screen could not have made is answered with a redirect and a
     * message, and changes nothing.
     *
     * @param list<string> $actions
     * @return array{action: string, ids: list<int>}|Response
     */
    protected function bulkInput(Request $request, string $tokenAction, array $actions, string $subjects, string $backUrl): array|Response
    {
        $problem = null;
        $action = $request->input('bulk_action') ?? '';
        $posted = $request->inputList('ids');

        if (!$this->validToken($request, $tokenAction)) {
            $problem = 'Your session expired, so nothing was saved. Please check the form and send it again.';
        } elseif (!in_array($action, $actions, true)) {
            $problem = 'Choose what to do with the selected ' . $subjects . '.';
        } elseif (BulkResult::tooMany($posted)) {
            $problem = 'Too many ' . $subjects . ' were selected at once.';
        } elseif (BulkResult::ids($posted) === []) {
            $problem = 'Tick at least one first.';
        }

        if ($problem !== null) {
            $this->flash->add('error', $problem);

            return Response::redirect($backUrl, 303);
        }

        return ['action' => $action, 'ids' => BulkResult::ids($posted)];
    }

    /**
     * The shared page that asks before a bulk action that cannot be undone.
     *
     * @param list<array{id: int, label: string}> $items
     * @param array<string, string> $fields hidden fields to send back
     */
    protected function askBeforeBulk(Request $request, User $user, string $tokenAction, string $heading, string $note, string $button, array $items, array $fields, string $action, string $backUrl, string $backLabel, string $currentPath): Response
    {
        return $this->view->render('bulk-confirm', [
            'heading' => $heading,
            'note' => $note,
            'button' => $button,
            'items' => $items,
            'fields' => $fields,
            'csrfToken' => Csrf::token($tokenAction),
            'action' => $action,
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
        ], $user, $heading, $currentPath, $this->flash->pull($request->cookies));
    }

    protected function forbidden(User $user, Request $request, bool $withLayout, string $currentPath): Response
    {
        // An Author kept from writing by the site's publishing rule is told that, not just "access denied".
        $data = ['signOutToken' => Csrf::token('admin_logout'), 'publishingOff' => PublishingPolicy::effectiveRole($user->role) !== $user->role];

        if ($withLayout) {
            return $this->view->render('forbidden', $data, $user, 'Access denied', $currentPath, $this->flash->pull($request->cookies), 403);
        }

        return $this->view->renderBare('forbidden-bare', $data, 'Access denied', 403);
    }
}
