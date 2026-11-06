<?php

/**
 * Settings > Comments: whether readers can comment, how a first comment is treated, and the limits.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
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
use LumoraFiction\Services\CommentSettings;

final class CommentSettingsController extends AdminController
{
    private const PATH = 'settings/comments';

    private const TOKEN = 'admin_comment_settings';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly CommentSettings $settings,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        return $user instanceof Response ? $user : $this->page($request, $user, $this->settings->current(), [], 200);
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
            'trust' => $request->input('trust'),
            'author_toggle' => $request->checked('author_toggle'),
            'edit_minutes' => $request->input('edit_minutes'),
            'report_threshold' => $request->input('report_threshold'),
        ]);

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->page($request, $user, $result['values'], ['form' => 'Your session expired, so nothing was saved. Please check the settings and save again.'], 400);
        }

        if ($result['errors'] !== []) {
            return $this->page($request, $user, $result['values'], $result['errors'], 422);
        }

        $changed = $this->settings->save($result['values'], $user, $request->ip());
        $this->flash->add('success', $changed === [] ? 'Nothing needed changing.' : 'Comment settings saved.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array{enabled: bool, trust: string, author_toggle: bool, edit_minutes: int, report_threshold: int} $values
     * @param array<string, string> $errors
     */
    private function page(Request $request, User $user, array $values, array $errors, int $status): Response
    {
        return $this->view->render('settings-comments', [
            'values' => $values,
            'errors' => $errors,
            'maxMinutes' => CommentSettings::MAX_EDIT_MINUTES,
            'maxThreshold' => CommentSettings::MAX_THRESHOLD,
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH),
        ], $user, 'Comments', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
