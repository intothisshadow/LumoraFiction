<?php

/**
 * Settings › Feeds.
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
use LumoraFiction\Services\FeedSettings;

final class FeedSettingsController extends AdminController
{
    private const PATH = 'settings/feeds';

    private const TOKEN = 'admin_settings_feeds';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly FeedSettings $settings,
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

        $result = $this->settings->validate(
            array_values(array_filter($request->inputList('kinds'), 'is_string')),
            $request->checked('json'),
            (string) $request->input('limit'),
            $request->checked('full_text'),
        );

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
     * @param array{kinds: list<string>, json: bool, limit: int, full_text: bool} $values
     * @param array<string, string> $errors
     */
    private function page(Request $request, User $user, array $values, array $errors, int $status = 200): Response
    {
        $kinds = [];

        foreach (FeedSettings::KINDS as $kind => $label) {
            $kinds[] = ['value' => $kind, 'label' => $label, 'checked' => in_array($kind, $values['kinds'], true)];
        }

        return $this->view->render('settings-feeds', [
            'values' => $values,
            'kinds' => $kinds,
            'errors' => $errors,
            'minLimit' => FeedSettings::MIN_LIMIT,
            'maxLimit' => FeedSettings::MAX_LIMIT,
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH),
        ], $user, 'Feed settings', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
