<?php

/**
 * A signed-in member's own privacy page: download a copy of their data, or delete their account.
 *
 * @package LumoraFiction
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers;

use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AccountDataExporter;
use LumoraFiction\Services\AccountDeletionException;
use LumoraFiction\Services\AccountDeletionService;
use LumoraFiction\Services\AuditLogService;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\PrivacySettings;

/**
 * Both actions work only on the signed-in person's own account, and both are POSTs with a one-use token. Deleting
 * also asks for the current password, so a session left open on a shared computer cannot be used to destroy an account.
 */
final class PrivacyController
{
    private const EXPIRED = 'Your session expired. Please try again.';

    public function __construct(
        private readonly ThemeRenderer $themes,
        private readonly AuthService $auth,
        private readonly FlashMessages $flash,
        private readonly AccountDataExporter $exporter,
        private readonly AccountDeletionService $deletion,
        private readonly PrivacySettings $settings,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function show(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        return $user === null ? $this->mustSignIn() : $this->page($request, $user);
    }

    /**
     * @param array<string, string> $params
     */
    public function export(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        if (!Csrf::verify('account_data_export', $request->input('csrf_token'), $request->cookies)) {
            return $this->page($request, $user, [], 400, self::EXPIRED);
        }

        $this->audit->record($user->id, 'privacy.export', 'user', (string) $user->id, ['self' => true], $request->ip());
        $json = json_encode($this->exporter->export($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return Response::download($json, 'my-account-data.json', 'application/json')->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        if (!Csrf::verify('account_delete', $request->input('csrf_token'), $request->cookies)) {
            return $this->page($request, $user, [], 400, self::EXPIRED);
        }

        $problem = $this->auth->confirmPassword($user, $request->input('current_password') ?? '', $request->ip());

        if ($problem !== null) {
            return $this->page($request, $user, ['current_password' => $problem], 422);
        }

        $keep = null;

        // An author decides about their own writing; the site setting is only the suggestion on the form.
        if ($this->deletion->storyCount($user) > 0) {
            $choice = $request->input('stories');

            if ($choice !== 'keep' && $choice !== 'delete') {
                return $this->page($request, $user, ['stories' => 'Choose what happens to your stories.'], 422);
            }

            $keep = $choice === 'keep';
        }

        try {
            $this->deletion->delete($user, $user, $request->ip(), $keep);
        } catch (AccountDeletionException $exception) {
            return $this->page($request, $user, [], 422, $exception->getMessage());
        }

        // The session still names the account, so it is ended here and not left to expire.
        $this->auth->logout($request->cookies);
        $this->flash->add('success', 'Your account has been deleted.');

        return Response::redirect(PublicUrl::account('sign-in'), 303);
    }

    /**
     * @param array<string, string> $errors field name => message
     */
    private function page(Request $request, User $user, array $errors = [], int $status = 200, string $summary = ''): Response
    {
        $keeps = $this->settings->keepsStories();

        $forms = [
            [
                'id' => 'account_data_export',
                'action' => PublicUrl::account('privacy/export'),
                'csrf_token' => Csrf::token('account_data_export'),
                'submit' => 'Download my data',
                'heading' => 'Your data',
                'fields' => [],
                'errors' => [],
                'intro' => 'A file with the details of your account, your stories, bookmarks, reading history and follows. It never includes your password.',
            ],
            [
                'id' => 'account_delete',
                'action' => PublicUrl::account('privacy/delete'),
                'csrf_token' => Csrf::token('account_delete'),
                'submit' => 'Delete my account',
                'heading' => 'Delete your account',
                'fields' => array_merge($this->storyFields($user, $keeps, $errors), [[
                    'name' => 'current_password',
                    'id' => 'lf-field-current-password',
                    'label' => 'Current password',
                    'type' => 'password',
                    'value' => '',
                    'autocomplete' => 'current-password',
                    'hint' => 'This removes your email address, login name, display name, password, bookmarks, reading history and follows for good. You will be signed out and cannot undo this.',
                    'error' => $errors['current_password'] ?? '',
                    'options' => [],
                ]]),
                'errors' => $summary !== '' ? [['message' => $summary]] : [],
            ],
        ];

        $response = $this->themes->render('account', [
            'intro' => 'Download a copy of what this site holds about you, or delete your account.',
            'notices' => array_map(static fn (array $message): array => ['type' => $message['type'], 'text' => $message['message']], $this->flash->pull($request->cookies)),
            'forms' => $forms,
            'links' => [['label' => 'Back to your account', 'url' => PublicUrl::account('profile')]],
            'shortcuts' => [],
        ], $status, ['title' => 'Your data and privacy', 'noindex' => true, 'variant' => 'profile']);

        return $response->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * The choice about the member's stories, asked only of someone who has some. The site setting preselects an answer.
     *
     * @param array<string, string> $errors
     * @return list<array<string, mixed>>
     */
    private function storyFields(User $user, bool $siteKeeps, array $errors): array
    {
        if ($this->deletion->storyCount($user) === 0) {
            return [];
        }

        return [[
            'name' => 'stories',
            'id' => 'lf-field-stories',
            'label' => 'Your stories',
            'type' => 'select',
            'value' => '',
            'autocomplete' => 'off',
            'hint' => 'Keeping them leaves your stories on the site under the name "' . $this->settings->neutralName() . '", with nothing that identifies you. Deleting removes them and all their chapters for good.',
            'error' => $errors['stories'] ?? '',
            'options' => [
                ['value' => '', 'label' => 'Choose…', 'selected' => false],
                ['value' => 'keep', 'label' => 'Keep my stories under a neutral name', 'selected' => false],
                ['value' => 'delete', 'label' => 'Delete my stories too', 'selected' => false],
            ],
        ]];
    }

    private function mustSignIn(): Response
    {
        return Response::redirect(PublicUrl::account('sign-in'), 303);
    }
}
