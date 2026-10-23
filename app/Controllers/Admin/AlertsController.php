<?php

/**
 * The Alerts screen: what needs an Administrator's attention about the site itself.
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
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\AlertSeverity;
use LumoraFiction\Models\Capability;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\SiteAlerts;

/**
 * Administrators only, checked on the server for the screen and for each change. The list never carries anything an
 * Editor or Author could use, and a refused request changes nothing.
 */
final class AlertsController extends AdminController
{
    private const PATH = 'maintenance/alerts';

    private const TOKEN = 'admin_alerts';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly SiteAlerts $alerts,
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

        $groups = [];

        foreach (AlertSeverity::cases() as $severity) {
            $groups[$severity->value] = [];
        }

        foreach ($this->alerts->all($user) as $alert) {
            $groups[$alert->severity->value][] = $alert;
        }

        return $this->view->render('alerts', [
            'groups' => $groups,
            'emailsCritical' => $this->alerts->emailsCritical(),
            'csrfToken' => Csrf::token(self::TOKEN),
            'listUrl' => AdminUrl::to(self::PATH),
            'dismissUrl' => AdminUrl::to(self::PATH),
            'emailUrl' => AdminUrl::to(self::PATH . '/email'),
            'retentionDays' => SiteAlerts::RETENTION_DAYS,
        ], $user, 'Alerts', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $params
     */
    public function dismiss(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $id = ctype_digit($params['id'] ?? '') ? (int) $params['id'] : 0;

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', 'Your session expired, so nothing was changed. Please try again.');
        } elseif ($id > 0 && $this->alerts->dismiss($user, $id, $request->ip())) {
            $this->flash->add('success', 'The alert is hidden. It comes back if the problem returns after a quiet week.');
        } else {
            $this->flash->add('error', 'That alert is not there any more.');
        }

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function saveEmail(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', 'Your session expired, so nothing was saved. Please try again.');
        } else {
            $this->alerts->setEmailCritical($user, $request->checked('email_critical'), $request->ip());
            $this->flash->add('success', 'Saved.');
        }

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }
}
