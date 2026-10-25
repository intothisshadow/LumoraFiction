<?php

/**
 * Maintenance › Maintenance mode: taking the public site offline for visitors, now or on a schedule.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
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
use LumoraFiction\Services\MaintenanceMode;

final class MaintenanceModeController extends AdminController
{
    private const PATH = 'maintenance/mode';

    private const TOKEN = 'admin_maintenance_mode';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly MaintenanceMode $maintenance,
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

        return $this->page($request, $user, $this->maintenance->current(), []);
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

        $result = $this->maintenance->validate([
            'enabled' => $request->checked('enabled'),
            'message' => $request->input('message'),
            'allowlist' => $request->input('allowlist'),
            'starts' => $request->input('starts'),
            'ends' => $request->input('ends'),
        ]);

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->page($request, $user, $result['values'], ['form' => 'Your session expired, so nothing was saved. Please check the settings and save again.'], 400);
        }

        if ($result['errors'] !== []) {
            return $this->page($request, $user, $result['values'], $result['errors'], 422);
        }

        $changed = $this->maintenance->save($result['values'], $user, $request->ip());

        $this->flash->add('success', $changed === [] ? 'Nothing needed changing.' : 'Maintenance settings saved.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array{enabled: bool, message: string, allowlist: string, starts: string, ends: string} $values
     * @param array<string, string> $errors
     */
    private function page(Request $request, User $user, array $values, array $errors, int $status = 200): Response
    {
        return $this->view->render('maintenance-mode', [
            'values' => $values,
            'errors' => $errors,
            'active' => $this->maintenance->isActive(),
            'yourAddress' => $request->ip(),
            'zone' => $this->maintenance->zone()->getName(),
            'defaultMessage' => MaintenanceMode::DEFAULT_MESSAGE,
            'maxMessage' => MaintenanceMode::MAX_MESSAGE,
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH),
        ], $user, 'Maintenance mode', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
