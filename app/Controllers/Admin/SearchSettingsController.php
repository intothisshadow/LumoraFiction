<?php

/**
 * The Search engines settings screen.
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
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\SearchSettings;

final class SearchSettingsController extends AdminController
{
    private const PATH = 'settings/search';

    private const TOKEN = 'admin_settings_search';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly SearchSettings $settings,
        private readonly Configuration $config,
        private readonly string $siteUrl,
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

        $values = ['discourage' => $request->checked('discourage')];

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->page($request, $user, $values, ['form' => 'Your session expired, so nothing was saved. Please check the setting and save again.'], 400);
        }

        $changed = $this->settings->save($values, $user, $request->ip());

        $this->flash->add('success', $changed === [] ? 'Nothing needed changing.' : 'Settings saved.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array{discourage: bool} $values
     * @param array<string, string> $errors
     */
    private function page(Request $request, User $user, array $values, array $errors, int $status = 200): Response
    {
        return $this->view->render('settings-search', [
            'values' => $values,
            'errors' => $errors,
            'sitemapOffered' => SearchSettings::sitemapOffered($this->config) && $this->siteUrl !== '',
            'signInRequired' => $this->config->setting('reading.require_signin') === true,
            'sitemapUrl' => $this->siteUrl === '' ? '' : PublicUrl::absolute($this->siteUrl, PublicUrl::sitemap()),
            'robotsUrl' => $this->siteUrl === '' ? '' : PublicUrl::absolute($this->siteUrl, PublicUrl::robots()),
            'redirectsUrl' => AdminUrl::to('settings/redirects'),
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH),
        ], $user, 'Search engines', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
