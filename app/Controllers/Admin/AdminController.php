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
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
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

    protected function forbidden(User $user, Request $request, bool $withLayout, string $currentPath): Response
    {
        $data = ['signOutToken' => Csrf::token('admin_logout')];

        if ($withLayout) {
            return $this->view->render('forbidden', $data, $user, 'Access denied', $currentPath, $this->flash->pull($request->cookies), 403);
        }

        return $this->view->renderBare('forbidden-bare', $data, 'Access denied', 403);
    }
}
