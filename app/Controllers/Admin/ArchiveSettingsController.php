<?php

/**
 * Settings › Archive: what the site publishes, who may join, and what waits for approval.
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
use LumoraFiction\Models\UserRole;
use LumoraFiction\Services\ArchiveSettings;
use LumoraFiction\Services\AuthService;

final class ArchiveSettingsController extends AdminController
{
    private const PATH = 'settings/archive';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly ArchiveSettings $settings,
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
            'archive_mode' => $request->input('archive_mode'),
            'default_role' => $request->input('default_role'),
            'registration_enabled' => $request->checked('registration_enabled'),
            'email_verification' => $request->checked('email_verification'),
            'hold_first_story' => $request->checked('hold_first_story'),
        ];

        if (!$this->validToken($request, 'admin_settings_archive')) {
            return $this->page($request, $user, $this->settings->validate($submitted)['values'], ['form' => 'Your session expired, so nothing was saved. Please check the settings and save again.'], 400);
        }

        $result = $this->settings->validate($submitted);

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
        return $this->view->render('settings-archive', [
            'values' => $values,
            'errors' => $errors,
            'modes' => ArchiveSettings::MODES,
            'roles' => array_map(static fn (UserRole $role): array => ['value' => $role->value, 'label' => $role->label()], UserRole::selfServiceRoles()),
            'csrfToken' => Csrf::token('admin_settings_archive'),
            'action' => AdminUrl::to(self::PATH),
        ], $user, 'Archive settings', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
