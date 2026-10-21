<?php

/**
 * Maintenance › Plugins: the installed plugins, turning them on and off.
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
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\PluginManager;

final class PluginsController extends AdminController
{
    private const PATH = 'maintenance/plugins';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly PluginManager $plugins,
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

        return $this->view->render('plugins', [
            'plugins' => $this->plugins->listing(),
            'csrfToken' => Csrf::token('admin_plugins'),
            'activateAction' => AdminUrl::to(self::PATH . '/activate'),
            'deactivateAction' => AdminUrl::to(self::PATH . '/deactivate'),
        ], $user, 'Plugins', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $params
     */
    public function activate(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, 'admin_plugins')) {
            $this->flash->add('error', 'Your session expired, so nothing was changed. Please try again.');
        } else {
            $problem = $this->plugins->activate((string) $request->input('plugin'), $user, $request->ip());

            if ($problem === null) {
                $this->flash->add('success', 'The plugin was turned on.');
            } else {
                $this->flash->add('error', $problem);
            }
        }

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function deactivate(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, 'admin_plugins')) {
            $this->flash->add('error', 'Your session expired, so nothing was changed. Please try again.');
        } elseif ($this->plugins->deactivate((string) $request->input('plugin'), $user, $request->ip())) {
            $this->flash->add('success', 'The plugin was turned off.');
        } else {
            $this->flash->add('error', 'That plugin is not turned on.');
        }

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }
}
