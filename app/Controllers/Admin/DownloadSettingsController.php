<?php

/**
 * Settings › Downloads: the site-wide switch, the formats offered and whether download counts are kept and shown.
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
use LumoraFiction\Models\DownloadFormat;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\DownloadSettings;
use LumoraFiction\Services\StoryDownloads;

final class DownloadSettingsController extends AdminController
{
    private const PATH = 'settings/downloads';

    private const TOKEN = 'admin_settings_downloads';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly DownloadSettings $settings,
        private readonly ?StoryDownloads $downloads = null,
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
            $request->checked('enabled'),
            array_values(array_filter($request->inputList('formats'), 'is_string')),
            $request->checked('count'),
            $request->checked('show_counts'),
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
     * @param array{enabled: bool, formats: list<string>, count: bool, show_counts: bool} $values
     * @param array<string, string> $errors
     */
    private function page(Request $request, User $user, array $values, array $errors, int $status = 200): Response
    {
        $formats = [];

        foreach (DownloadFormat::cases() as $format) {
            $formats[] = [
                'value' => $format->value,
                'label' => $format->label(),
                'description' => $format->description(),
                'checked' => in_array($format->value, $values['formats'], true),
                // A format this server cannot make stays listed, so the administrator can see why it is not offered.
                'available' => $this->downloads === null || $this->downloads->canMake($format),
            ];
        }

        return $this->view->render('settings-downloads', [
            'values' => $values,
            'formats' => $formats,
            'errors' => $errors,
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH),
        ], $user, 'Download settings', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
