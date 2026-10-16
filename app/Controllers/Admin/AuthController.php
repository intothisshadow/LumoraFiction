<?php

/**
 * Admin sign-in and sign-out.
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
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Services\LoginOutcome;

/**
 * After signing in, a person always goes to the admin home. There is deliberately no "return to"
 * address: accepting one from the request is how an open redirect gets built.
 */
final class AuthController extends AdminController
{
    /**
     * @param array<string, string> $params
     */
    public function showLogin(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user !== null && $user->can(Capability::AccessAdmin)) {
            return Response::redirect(AdminUrl::to());
        }

        return $this->loginPage($request, null, '', 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function login(array $params, Request $request): Response
    {
        $identity = trim($request->input('login_name') ?? '');
        $password = $request->input('password') ?? '';

        if (!$this->validToken($request, 'admin_login')) {
            return $this->loginPage($request, 'Your session expired. Please try again.', $identity, 400);
        }

        $result = $this->auth->attempt($identity, $password, $request->ip());

        if (!$result->succeeded()) {
            $response = $this->loginPage($request, $result->message(), $identity, $result->outcome === LoginOutcome::Locked ? 429 : 401);

            return $result->outcome === LoginOutcome::Locked
                ? $response->withHeader('Retry-After', (string) max(1, $result->retryAfterSeconds))
                : $response;
        }

        // A Reader may have a valid account but has nothing to do in the admin area. Signing them
        // straight back out stops a half-signed-in state, and the message says why.
        if ($result->user === null || !$result->user->can(Capability::AccessAdmin)) {
            $this->auth->logout($request->cookies);

            return $this->loginPage($request, 'This account does not have access to the admin area.', '', 403);
        }

        return Response::redirect(AdminUrl::to(), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function logout(array $params, Request $request): Response
    {
        // A page on another site must not be able to sign someone out by making their browser send a request.
        if (!$this->validToken($request, 'admin_logout')) {
            return Response::redirect(AdminUrl::to(), 303);
        }

        $this->auth->logout($request->cookies);
        $this->flash->add('success', 'You have been signed out.');

        return Response::redirect(AdminUrl::to('login'), 303);
    }

    private function loginPage(Request $request, ?string $error, string $identity, int $status): Response
    {
        return $this->view->renderBare('login', [
            'error' => $error,
            'identity' => $identity,
            'csrfToken' => Csrf::token('admin_login'),
            'messages' => $this->flash->pull($request->cookies),
            'loginUrl' => AdminUrl::to('login'),
        ], 'Sign in', $status);
    }
}
