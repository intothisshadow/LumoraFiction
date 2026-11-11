<?php

/**
 * Settings › Cache: what is cached and for how long, the outside cache to talk to, and a check of the headers a visitor gets.
 *
 * @package LumoraFiction
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers\Admin;

use Closure;
use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Admin\AdminView;
use LumoraFiction\Core\Cache\PageCache;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\CacheSettings;

final class CacheSettingsController extends AdminController
{
    private const PATH = 'settings/cache';

    private const TOKEN = 'admin_settings_cache';

    /**
     * @param Closure(): bool $visitorChoice
     * @param Closure(): bool $signInRequired
     * @param Closure(): bool $viewsCounted whether a plugin listens for story views
     */
    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly CacheSettings $settings,
        private readonly PageCache $cache,
        private readonly Closure $visitorChoice,
        private readonly Closure $signInRequired,
        private readonly Closure $viewsCounted,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        return $user instanceof Response ? $user : $this->page($request, $user, $this->settings->current(), []);
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

        $result = $this->settings->validate([
            'enabled' => $request->checked('enabled'),
            'types' => $request->inputList('types'),
            'lifetime' => $request->input('lifetime'),
            'browser_lifetime' => $request->input('browser_lifetime'),
            'exclusions' => $request->input('exclusions'),
            'driver' => $request->input('driver'),
        ]);

        if (!$this->validToken($request, self::TOKEN)) {
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
     * @param array{enabled: bool, types: list<string>, lifetime: int, browser_lifetime: int, exclusions: string, driver: string} $values
     * @param array<string, string> $errors
     */
    private function page(Request $request, User $user, array $values, array $errors, int $status = 200): Response
    {
        $detected = PageCache::liteSpeedDetected($request->server);
        $saved = $this->settings->current();

        return $this->view->render('settings-cache', [
            'values' => $values,
            'errors' => $errors,
            'kinds' => PageCache::KINDS,
            'drivers' => CacheSettings::DRIVERS,
            'warnings' => $this->settings->warnings($saved, $detected, ($this->visitorChoice)(), ($this->signInRequired)(), ($this->viewsCounted)()),
            'detected' => $detected,
            'driverInUse' => $this->cache->driverFor($request),
            'purges' => $this->cache->recentPurges(),
            'samples' => [
                'Front page' => PublicUrl::home(),
                'Browse' => PublicUrl::browse(),
                'News' => PublicUrl::news(),
                'Search' => PublicUrl::search(),
            ],
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH),
        ], $user, 'Cache settings', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
