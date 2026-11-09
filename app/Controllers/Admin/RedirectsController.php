<?php

/**
 * The Redirects screen: the list, adding one, and deleting one.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
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
use LumoraFiction\Services\RedirectException;
use LumoraFiction\Services\RedirectService;

final class RedirectsController extends AdminController
{
    private const PATH = 'settings/redirects';

    private const TOKEN = 'admin_redirects';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly RedirectService $redirects,
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

        return $this->page($request, $user, ['source' => '', 'target' => '', 'status' => '301'], []);
    }

    /**
     * @param array<string, string> $params
     */
    public function add(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $values = [
            'source' => (string) $request->input('source'),
            'target' => (string) $request->input('target'),
            'status' => (string) $request->input('status'),
        ];

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->page($request, $user, $values, ['form' => 'Your session expired, so nothing was saved. Please try again.'], 400);
        }

        try {
            $this->redirects->add($user, $values['source'], $values['target'], (int) $values['status'], $request->ip());
        } catch (RedirectException $exception) {
            return $this->page($request, $user, $values, [$exception->field => $exception->getMessage()], 422);
        }

        $this->flash->add('success', 'The redirect was added.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->page($request, $user, ['source' => '', 'target' => '', 'status' => '301'], ['form' => 'Your session expired, so nothing was deleted. Please try again.'], 400);
        }

        $id = filter_var($params['id'] ?? '', FILTER_VALIDATE_INT);
        $done = $id !== false && $this->redirects->delete($user, $id, $request->ip());
        $this->flash->add($done ? 'success' : 'warning', $done ? 'The redirect was removed.' : 'That redirect is not there.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array{source: string, target: string, status: string} $values
     * @param array<string, string> $errors
     */
    private function page(Request $request, User $user, array $values, array $errors, int $status = 200): Response
    {
        $total = $this->redirects->count();
        $pages = max(1, (int) ceil($total / RedirectService::PAGE_SIZE));
        $number = min($pages, max(1, (int) ($request->queryValue('page') ?? 1)));

        return $this->view->render('redirects', [
            'redirects' => $this->redirects->list(RedirectService::PAGE_SIZE, ($number - 1) * RedirectService::PAGE_SIZE),
            'values' => $values,
            'errors' => $errors,
            'statuses' => RedirectService::STATUSES,
            'total' => $total,
            'page' => $number,
            'pages' => $pages,
            'listUrl' => AdminUrl::to(self::PATH),
            'deleteBase' => AdminUrl::to(self::PATH),
            'searchUrl' => AdminUrl::to('settings/search'),
            'csrfToken' => Csrf::token(self::TOKEN),
        ], $user, 'Redirects', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
