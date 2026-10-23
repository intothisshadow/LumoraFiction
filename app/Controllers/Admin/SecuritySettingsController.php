<?php

/**
 * Settings › Security: the sign-in lockout, the reverse proxies the site trusts, the HTTPS-only header, the minimum password length and the session length.
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
use LumoraFiction\Services\SecuritySettings;

final class SecuritySettingsController extends AdminController
{
    private const PATH = 'settings/security';

    private const FIELDS = ['lockout_attempts', 'lockout_minutes', 'trusted_proxies', 'password_min_length', 'session_hours'];

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly SecuritySettings $settings,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        return $this->page($request, $user, $this->settings->current(), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $submitted = [
            'lockout_progressive' => $request->checked('lockout_progressive'),
            'notify_owner' => $request->checked('notify_owner'),
            'hsts' => $request->checked('hsts'),
        ];

        foreach (self::FIELDS as $field) {
            $submitted[$field] = $request->input($field);
        }

        $result = $this->settings->validate($submitted, $request->isHttps());

        if (!$this->validToken($request, 'admin_settings_security')) {
            return $this->page($request, $user, $result['values'], ['form' => 'Your session expired, so nothing was saved. Please check the settings and save again.'], 400);
        }

        if ($result['errors'] !== []) {
            return $this->page($request, $user, $result['values'], $result['errors'], 422);
        }

        $changed = $this->settings->save($result['values'], $user, $request->ip());

        $this->flash->add('success', $changed === [] ? 'Nothing needed changing.' : 'Settings saved.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function page(Request $request, User $user, array $values, array $errors, int $status = 200): Response
    {
        return $this->view->render('settings-security', [
            'values' => $values,
            'errors' => $errors,
            'peer' => $request->peer(),
            'address' => $request->ip(),
            'https' => $request->isHttps(),
            'csrfToken' => Csrf::token('admin_settings_security'),
            'action' => AdminUrl::to(self::PATH),
        ], $user, 'Security settings', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
