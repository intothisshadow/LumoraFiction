<?php

/**
 * The public account pages: sign-in, registration, email confirmation, password reset and profile settings.
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

namespace LumoraFiction\Controllers;

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Http\BasePath;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\EditorMode;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AccountRules;
use LumoraFiction\Services\AuditLogService;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\EditorSettings;
use LumoraFiction\Services\FormGuard;
use LumoraFiction\Services\GuardDecision;
use LumoraFiction\Services\GuardOutcome;
use LumoraFiction\Services\LoginOutcome;
use LumoraFiction\Services\ReaderPreferences;
use LumoraFiction\Services\RegistrationGuard;
use LumoraFiction\Services\StoryFollowService;
use LumoraFiction\Services\UserService;

/**
 * Every page is built from the same bundled form description, so a theme draws all of them with one template and
 * one partial. Nothing here reveals whether an address or a login name has an account: the messages come from the
 * services unchanged, and the pages that take an address answer identically whether or not it is registered.
 * There is deliberately no "return to" address after signing in, because accepting one is how an open redirect is built.
 */
final class AccountController
{
    private const EXPIRED = 'Your session expired. Please try again.';

    public function __construct(
        private readonly ThemeRenderer $themes,
        private readonly AuthService $auth,
        private readonly UserService $users,
        private readonly EditorSettings $editor,
        private readonly FlashMessages $flash,
        private readonly Configuration $config,
        private readonly AuditLogService $audit,
        private readonly ReaderPreferences $readerPreferences,
        private readonly RegistrationGuard $registrationGuard,
        private readonly FormGuard $formGuard,
        private readonly StoryFollowService $follows,
    ) {
    }

    // ---- sign in and out ------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $params
     */
    public function showSignIn(array $params, Request $request): Response
    {
        if ($this->auth->user($request->cookies) !== null) {
            return Response::redirect(PublicUrl::account('profile'), 302);
        }

        return $this->signInPage($request);
    }

    /**
     * @param array<string, string> $params
     */
    public function signIn(array $params, Request $request): Response
    {
        $identity = trim($request->input('identity') ?? '');

        if (!$this->validToken($request, 'account_sign_in')) {
            return $this->signInPage($request, self::EXPIRED, $identity, 400);
        }

        $result = $this->auth->attempt($identity, $request->input('password') ?? '', $request->ip());

        if ($result->succeeded()) {
            return Response::redirect(BasePath::get() . '/', 303)->withHeader('Cache-Control', 'private, no-store');
        }

        $locked = $result->outcome === LoginOutcome::Locked;
        $message = $result->message();

        if ($locked) {
            $minutes = max(1, (int) ceil($result->retryAfterSeconds / 60));
            $message .= ' You can try again in about ' . $minutes . ($minutes === 1 ? ' minute.' : ' minutes.');
        }

        $links = $result->outcome === LoginOutcome::EmailNotVerified ? [['label' => 'Send the confirmation email again', 'url' => PublicUrl::account('resend')]] : [];
        $response = $this->signInPage($request, $message, $identity, $locked ? 429 : 401, $links);

        return $locked ? $response->withHeader('Retry-After', (string) max(1, $result->retryAfterSeconds)) : $response;
    }

    /**
     * @param array<string, string> $params
     */
    public function signOut(array $params, Request $request): Response
    {
        // A page on another site must not be able to sign someone out by making their browser send a request.
        if (!$this->validToken($request, 'account_sign_out')) {
            return Response::redirect(PublicUrl::account('profile'), 303);
        }

        $this->auth->logout($request->cookies);
        $this->flash->add('success', 'You have been signed out.');

        return Response::redirect(PublicUrl::account('sign-in'), 303);
    }

    /**
     * @param list<array{label: string, url: string}> $links
     */
    private function signInPage(Request $request, ?string $error = null, string $identity = '', int $status = 200, array $links = []): Response
    {
        $form = $this->form('account_sign_in', PublicUrl::account('sign-in'), 'Sign in', [
            $this->field('identity', 'Login name or email address', 'text', $identity, 'username'),
            $this->field('password', 'Password', 'password', '', 'current-password'),
        ], '', $error === null ? [] : [$error]);

        $links = [
            ...$links,
            ['label' => 'Forgot your password?', 'url' => PublicUrl::account('forgot')],
            ...($this->registrationOpen() ? [['label' => 'Create an account', 'url' => PublicUrl::account('register')]] : []),
        ];

        return $this->page($request, 'Sign in', 'sign-in', [$form], $status, [], $links);
    }

    // ---- registration and email confirmation ------------------------------------------------------------------

    /**
     * @param array<string, string> $params
     */
    public function showRegister(array $params, Request $request): Response
    {
        if ($this->auth->user($request->cookies) !== null) {
            return Response::redirect(PublicUrl::account('profile'), 302);
        }

        return $this->registerPage($request);
    }

    /**
     * @param array<string, string> $params
     */
    public function register(array $params, Request $request): Response
    {
        $values = [
            'login_name' => trim($request->input('login_name') ?? ''),
            'display_name' => trim($request->input('display_name') ?? ''),
            'email' => trim($request->input('email') ?? ''),
        ];

        if (!$this->validToken($request, 'account_register')) {
            return $this->registerPage($request, $values, ['form' => self::EXPIRED], 400);
        }

        $decision = $this->registrationGuard->check($request, $values['display_name'], $values['email'], $request->input('challenge_answer') ?? '');

        if (!$decision->allowed()) {
            return $this->refusedRegistration($request, $values, $decision);
        }

        $result = $this->auth->register($values['login_name'], $values['display_name'], $values['email'], $request->input('password') ?? '');

        if (!$result->accepted) {
            return $this->registerPage($request, $values, $result->errors, 422);
        }

        return $this->registrationReceived($request, $result->needsVerification);
    }

    /**
     * The same words whether or not the address already had an account, and whether or not the protections quietly
     * dropped the sign-up.
     */
    private function registrationReceived(Request $request, bool $needsVerification): Response
    {
        $message = $needsVerification
            ? 'Thank you for joining. We have sent a message to the address you gave. Open the link in it to confirm your address and activate your account.'
            : 'Thank you for joining. Your account is ready, and you can sign in now.';

        return $this->page($request, 'Registration received', 'message', [], 200, [['type' => 'success', 'text' => $message]], [
            ['label' => 'Go to the sign-in page', 'url' => PublicUrl::account('sign-in')],
        ]);
    }

    /**
     * @param array<string, string> $values
     */
    private function refusedRegistration(Request $request, array $values, GuardDecision $decision): Response
    {
        if ($decision->outcome === GuardOutcome::Silent) {
            return $this->registrationReceived($request, $this->config->setting('registration.email_verification', true) !== false);
        }

        if ($decision->outcome === GuardOutcome::Reject) {
            return $this->registerPage($request, $values, $decision->errors, 422);
        }

        $minutes = max(1, (int) ceil($decision->retryAfter / 60));
        $wait = $minutes === 1 ? 'about a minute' : 'about ' . $minutes . ' minutes';

        $message = $decision->outcome === GuardOutcome::Paused
            ? 'Registration is paused for a little while because many sign-ups arrived at once. Please try again in ' . $wait . '.'
            : 'You have tried to register several times in a short while. Please try again in ' . $wait . '.';

        return $this->registerPage($request, $values, ['form' => $message], $decision->outcome === GuardOutcome::Paused ? 503 : 429)
            ->withHeader('Retry-After', (string) $decision->retryAfter);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function registerPage(Request $request, array $values = [], array $errors = [], int $status = 200): Response
    {
        if (!$this->registrationOpen()) {
            return $this->page($request, 'Registration is closed', 'message', [], 403, [['type' => 'info', 'text' => 'New accounts cannot be created on this site at the moment.']], [
                ['label' => 'Go to the sign-in page', 'url' => PublicUrl::account('sign-in')],
            ]);
        }

        $fields = [
            $this->field('login_name', 'Login name', 'text', $values['login_name'] ?? '', 'username', 'Private. You use it to sign in, and it is never shown to other people.', $errors['login_name'] ?? ''),
            $this->field('display_name', 'Display name', 'text', $values['display_name'] ?? '', 'nickname', 'Public. This is the name shown on your stories and your author page.', $errors['display_name'] ?? ''),
            $this->field('email', 'Email address', 'email', $values['email'] ?? '', 'email', '', $errors['email'] ?? ''),
            $this->field('password', 'Password', 'password', '', 'new-password', '', $errors['password'] ?? ''),
        ];

        // A question the administrator wrote, asked with an ordinary field so every theme already draws it.
        $question = $this->registrationGuard->question();

        if ($question !== '') {
            $fields[] = $this->field('challenge_answer', $question, 'text', '', 'off', '', $errors['challenge'] ?? '');
        }

        $form = $this->form('account_register', PublicUrl::account('register'), 'Create account', $fields, '', isset($errors['form']) ? [$errors['form']] : []);

        return $this->page($request, 'Create an account', 'register', [$form], $status, [], [
            ['label' => 'Already have an account? Sign in', 'url' => PublicUrl::account('sign-in')],
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function verify(array $params, Request $request): Response
    {
        // The address in the link is a credential, so it is never passed on in a Referer header or kept by a cache.
        if ($this->auth->verifyEmail($params['token'] ?? '')) {
            return $this->page($request, 'Email address confirmed', 'message', [], 200, [['type' => 'success', 'text' => 'Your email address is confirmed and your account is active. You can sign in now.']], [
                ['label' => 'Sign in', 'url' => PublicUrl::account('sign-in')],
            ], true);
        }

        return $this->page($request, 'Link not valid', 'message', [], 400, [['type' => 'error', 'text' => 'This confirmation link is invalid or has expired.']], [
            ['label' => 'Send a new confirmation email', 'url' => PublicUrl::account('resend')],
        ], true);
    }

    /**
     * @param array<string, string> $params
     */
    public function showResend(array $params, Request $request): Response
    {
        return $this->emailRequestPage($request, 'account_resend', 'resend', 'Send the confirmation email again', 'Send confirmation email', 'Enter the address you registered with and we will send a new confirmation link.');
    }

    /**
     * @param array<string, string> $params
     */
    public function resend(array $params, Request $request): Response
    {
        return $this->handleEmailRequest($request, 'account_resend', 'resend', 'Send the confirmation email again', 'Send confirmation email', 'Enter the address you registered with and we will send a new confirmation link.', function (string $email): void {
            $this->auth->resendVerification($email);
        });
    }

    // ---- forgotten password ---------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $params
     */
    public function showForgot(array $params, Request $request): Response
    {
        return $this->emailRequestPage($request, 'account_forgot', 'forgot', 'Reset your password', 'Send reset link', 'Enter the email address on your account and we will send you a link to choose a new password.');
    }

    /**
     * @param array<string, string> $params
     */
    public function forgot(array $params, Request $request): Response
    {
        return $this->handleEmailRequest($request, 'account_forgot', 'forgot', 'Reset your password', 'Send reset link', 'Enter the email address on your account and we will send you a link to choose a new password.', function (string $email): void {
            $this->auth->requestPasswordReset($email);
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function showReset(array $params, Request $request): Response
    {
        $token = $params['token'] ?? '';

        // Checking the link does not use it up, so a mail scanner that opens it does not spoil it for the person.
        if (!$this->auth->passwordResetLinkIsValid($token)) {
            return $this->invalidResetLink($request);
        }

        return $this->resetPage($request, $token);
    }

    /**
     * @param array<string, string> $params
     */
    public function reset(array $params, Request $request): Response
    {
        $token = $params['token'] ?? '';

        if (!$this->validToken($request, 'account_reset')) {
            return $this->auth->passwordResetLinkIsValid($token)
                ? $this->resetPage($request, $token, self::EXPIRED, 400)
                : $this->invalidResetLink($request);
        }

        $password = $request->input('password') ?? '';

        if (!hash_equals($password, $request->input('password_confirm') ?? '')) {
            return $this->auth->passwordResetLinkIsValid($token)
                ? $this->resetPage($request, $token, 'The two passwords do not match.', 422)
                : $this->invalidResetLink($request);
        }

        $problem = $this->auth->resetPassword($token, $password);

        if ($problem === null) {
            $this->flash->add('success', 'Your password has been changed. You can sign in with it now.');

            return Response::redirect(PublicUrl::account('sign-in'), 303)->withHeader('Referrer-Policy', 'no-referrer')->withHeader('Cache-Control', 'no-store');
        }

        // The link may have just been used or have expired; either way the person is told only what they need to know.
        return $this->auth->passwordResetLinkIsValid($token)
            ? $this->resetPage($request, $token, $problem, 422)
            : $this->invalidResetLink($request);
    }

    private function resetPage(Request $request, string $token, ?string $error = null, int $status = 200): Response
    {
        $form = $this->form('account_reset', PublicUrl::account('reset/' . rawurlencode($token)), 'Change password', [
            $this->field('password', 'New password', 'password', '', 'new-password'),
            $this->field('password_confirm', 'New password again', 'password', '', 'new-password'),
        ], '', $error === null ? [] : [$error]);

        return $this->page($request, 'Choose a new password', 'reset', [$form], $status, [], [], true);
    }

    private function invalidResetLink(Request $request): Response
    {
        return $this->page($request, 'Link not valid', 'message', [], 400, [['type' => 'error', 'text' => 'This password reset link is invalid or has expired.']], [
            ['label' => 'Ask for a new link', 'url' => PublicUrl::account('forgot')],
        ], true);
    }

    // ---- profile ----------------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $params
     */
    public function profile(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        return $user === null ? $this->mustSignIn() : $this->profilePage($request, $user);
    }

    /**
     * @param array<string, string> $params
     */
    public function updateName(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        $name = $request->input('display_name') ?? '';

        if (!$this->validToken($request, 'account_name')) {
            return $this->profilePage($request, $user, ['name' => ['summary' => self::EXPIRED, 'values' => ['display_name' => $name]]], 400);
        }

        $problem = $this->users->changeDisplayName($user, $name);

        if ($problem !== null) {
            return $this->profilePage($request, $user, ['name' => ['summary' => $problem, 'values' => ['display_name' => $name]]], 422);
        }

        $this->audit->record($user->id, 'account.display_name.change', 'user', (string) $user->id, [], $request->ip());
        $this->flash->add('success', 'Your display name has been changed.');

        return Response::redirect(PublicUrl::account('profile'), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function updateEmail(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        $email = trim($request->input('email') ?? '');

        if (!$this->validToken($request, 'account_email')) {
            return $this->profilePage($request, $user, ['email' => ['summary' => self::EXPIRED, 'values' => ['email' => $email]]], 400);
        }

        $problem = $this->auth->changeEmail($user, $request->input('current_password') ?? '', $email, $request->ip());

        if ($problem !== null) {
            return $this->profilePage($request, $user, ['email' => ['summary' => $problem, 'values' => ['email' => $email]]], 422);
        }

        $this->audit->record($user->id, 'account.email.change', 'user', (string) $user->id, [], $request->ip());
        $this->flash->add('success', 'Your email address has been changed.');

        return Response::redirect(PublicUrl::account('profile'), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function updatePassword(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        if (!$this->validToken($request, 'account_password')) {
            return $this->profilePage($request, $user, ['password' => ['summary' => self::EXPIRED]], 400);
        }

        $new = $request->input('new_password') ?? '';

        if (!hash_equals($new, $request->input('new_password_confirm') ?? '')) {
            return $this->profilePage($request, $user, ['password' => ['summary' => 'The two new passwords do not match.']], 422);
        }

        $problem = $this->auth->changePassword($user, $request->input('current_password') ?? '', $new, $request->ip());

        if ($problem !== null) {
            return $this->profilePage($request, $user, ['password' => ['summary' => $problem]], 422);
        }

        $this->audit->record($user->id, 'account.password.change', 'user', (string) $user->id, [], $request->ip());
        $this->flash->add('success', 'Your password has been changed. Any other devices you were signed in on have been signed out.');

        return Response::redirect(PublicUrl::account('profile'), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function updateEditor(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        // The control is not drawn when the editor is locked or the person does not write; refusing here as well
        // means a hand-made request cannot get around either.
        if (!$this->editorChoiceAvailable($user)) {
            return Response::redirect(PublicUrl::account('profile'), 303);
        }

        if (!$this->validToken($request, 'account_editor')) {
            return $this->profilePage($request, $user, ['editor' => ['summary' => self::EXPIRED]], 400);
        }

        $choice = $request->input('editor_mode') ?? '';
        $mode = EditorMode::tryFrom($choice);

        if ($choice !== '' && $mode === null) {
            return $this->profilePage($request, $user, ['editor' => ['summary' => 'Choose one of the options listed.']], 422);
        }

        $this->editor->setUserMode($user->id, $mode);
        $this->flash->add('success', 'Your editing mode has been saved.');

        return Response::redirect(PublicUrl::account('profile'), 303);
    }

    /**
     * Saves the reading preferences from the profile form, or from the reader script, which sends them as it changes
     * them and is answered with a fresh token for its next change.
     *
     * @param array<string, string> $params
     */
    public function updateReading(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);
        $script = $request->input('script') === '1';

        if ($user === null) {
            return $script ? Response::plain('', 401)->withHeader('Cache-Control', 'no-store') : $this->mustSignIn();
        }

        if (!$this->validToken($request, 'reader_preferences')) {
            return $script
                ? Response::plain('', 400)->withHeader('Cache-Control', 'no-store')
                : $this->profilePage($request, $user, ['reading' => ['summary' => self::EXPIRED]], 400);
        }

        // The reading script knows only the three display choices, so it must not wipe the one it cannot see.
        $this->readerPreferences->save($user->id, [
            'scheme' => $request->input('scheme'),
            'size' => $request->input('size'),
            'width' => $request->input('width'),
            'updates' => $script ? ($this->readerPreferences->forUser($user->id)['updates'] ?? null) : $request->input('updates'),
        ]);

        if ($script) {
            return Response::plain(json_encode(['token' => Csrf::token('reader_preferences')], JSON_THROW_ON_ERROR), 200)->withHeader('Cache-Control', 'no-store');
        }

        $this->flash->add('success', 'Your reading preferences have been saved.');

        return Response::redirect(PublicUrl::account('profile'), 303);
    }

    /**
     * @param array<string, array{summary?: string, values?: array<string, string>}> $problems the problem and the typed values of the form that failed
     */
    private function profilePage(Request $request, User $user, array $problems = [], int $status = 200): Response
    {
        $summary = static fn (string $name): array => isset($problems[$name]['summary']) ? [$problems[$name]['summary']] : [];
        $typed = static fn (string $name, string $field, string $default): string => $problems[$name]['values'][$field] ?? $default;

        $forms = [
            $this->form('account_name', PublicUrl::account('profile/name'), 'Save display name', [
                $this->field('display_name', 'Display name', 'text', $typed('name', 'display_name', $user->displayName), 'nickname', 'Public. Your author page address does not change.'),
            ], 'Display name', $summary('name')),
            $this->form('account_email', PublicUrl::account('profile/email'), 'Change email address', [
                $this->field('email', 'Email address', 'email', $typed('email', 'email', $user->email), 'email', 'We tell the old address when this changes.'),
                $this->field('current_password', 'Current password', 'password', '', 'current-password'),
            ], 'Email address', $summary('email')),
            $this->form('account_password', PublicUrl::account('profile/password'), 'Change password', [
                $this->field('current_password', 'Current password', 'password', '', 'current-password'),
                $this->field('new_password', 'New password', 'password', '', 'new-password', 'Changing it signs you out everywhere else.'),
                $this->field('new_password_confirm', 'New password again', 'password', '', 'new-password'),
            ], 'Password', $summary('password')),
        ];

        if ($this->editorChoiceAvailable($user)) {
            $current = $this->editor->userMode($user->id);
            $options = [['value' => '', 'label' => 'Site default (' . $this->editor->siteDefault()->label() . ')', 'selected' => $current === null]];

            foreach (EditorMode::cases() as $mode) {
                $options[] = ['value' => $mode->value, 'label' => $mode->label(), 'selected' => $current === $mode];
            }

            $forms[] = $this->form('account_editor', PublicUrl::account('profile/editor'), 'Save editing mode', [
                $this->field('editor_mode', 'Editing mode', 'select', '', '', 'How the chapter editor opens for you.', '', $options),
            ], 'Editing', $summary('editor'));
        }

        $saved = $this->readerPreferences->forUser($user->id);
        $readingFields = [];

        foreach ([
            'scheme' => 'Colours',
            'size' => 'Text size',
            'width' => 'Reading width',
            'updates' => 'Tell me when a story I\'m reading has been updated',
        ] as $name => $label) {
            $options = [];

            foreach (ReaderPreferences::CHOICES[$name] as $choice) {
                $options[] = ['value' => $choice, 'label' => ReaderPreferences::LABELS[$name][$choice], 'selected' => ($saved[$name] ?? ReaderPreferences::CHOICES[$name][0]) === $choice];
            }

            $hint = match ($name) {
                'scheme' => 'These follow you to any device you sign in on.',
                'updates' => 'Applies to the stories you follow. A line on the story page and a count on your account page say when one has something new.',
                default => '',
            };
            $readingFields[] = $this->field($name, $label, 'select', '', '', $hint, '', $options);
        }

        $forms[] = $this->form('reader_preferences', PublicUrl::account('profile/reading'), 'Save reading preferences', $readingFields, 'Reading', $summary('reading'));
        $forms[] = $this->form('account_sign_out', PublicUrl::account('sign-out'), 'Sign out', [], 'Sign out');

        // The one place a person's own login name is printed back to them. The page is private and never cached.
        $intro = 'Your sign-in name is ' . $user->loginName . '. It is private and only used to sign in.';

        return $this->page($request, 'Your account', 'profile', $forms, $status, [], [], false, $intro, $this->shortcuts($user));
    }

    /**
     * Each link is offered only to someone who holds the capability behind it, so the page never
     * advertises an address the server would refuse.
     *
     * @return list<array{label: string, url: string}>
     */
    private function shortcuts(User $user): array
    {
        $shortcuts = [];

        if ($user->can(Capability::WriteStories)) {
            $shortcuts[] = ['label' => 'Post New Story', 'url' => AdminUrl::to('stories/new')];
            $shortcuts[] = ['label' => 'My stories', 'url' => AdminUrl::to('stories')];
            $shortcuts[] = ['label' => 'Author profile', 'url' => AdminUrl::to('profile')];
        }

        if ($user->can(Capability::ModerateStories)) {
            $shortcuts[] = ['label' => 'Awaiting approval', 'url' => AdminUrl::to('review')];
        }

        if ($user->can(Capability::Bookmark)) {
            $shortcuts[] = ['label' => 'Continue reading', 'url' => PublicUrl::account('continue')];

            // The count appears only for a reader who asked to be told, and only when there is something to count.
            $waiting = $this->readerPreferences->wantsUpdates($user->id) ? $this->follows->countUpdates($user) : 0;
            $shortcuts[] = ['label' => $waiting > 0 ? 'Updated stories (' . number_format($waiting) . ')' : 'Updated stories', 'url' => PublicUrl::account('updates')];
            $shortcuts[] = ['label' => 'My bookmarks', 'url' => PublicUrl::account('bookmarks')];
        }

        return $shortcuts;
    }

    private function editorChoiceAvailable(User $user): bool
    {
        return $user->can(Capability::WriteStories) && !$this->editor->isLocked();
    }

    private function mustSignIn(): Response
    {
        $this->flash->add('info', 'Please sign in to see your account.');

        return Response::redirect(PublicUrl::account('sign-in'), 302)->withHeader('Cache-Control', 'private, no-store');
    }

    // ---- shared pieces ---------------------------------------------------------------------------------------------------

    /**
     * @param callable(string): void $send
     */
    private function handleEmailRequest(Request $request, string $action, string $path, string $title, string $submit, string $intro, callable $send): Response
    {
        $email = trim($request->input('email') ?? '');

        if (!$this->validToken($request, $action)) {
            return $this->emailRequestPage($request, $action, $path, $title, $submit, $intro, self::EXPIRED, $email, 400);
        }

        $decision = $this->formGuard->inspect($request, $action);

        if ($decision->outcome === GuardOutcome::Reject) {
            return $this->emailRequestPage($request, $action, $path, $title, $submit, $intro, $decision->errors['form'] ?? self::EXPIRED, $email, 422);
        }

        if (AccountRules::emailProblem(AccountRules::normalizeEmail($email)) !== null) {
            return $this->emailRequestPage($request, $action, $path, $title, $submit, $intro, 'Enter a valid email address.', $email, 422);
        }

        // A caught bot is answered like anyone else, but no message is sent.
        if ($decision->allowed()) {
            $send($email);
        }

        // The same words whether or not the address belongs to an account.
        return $this->page($request, $title, 'message', [], 200, [['type' => 'success', 'text' => 'If that address belongs to an account, a message is on its way. Check your inbox, and your spam folder.']], [
            ['label' => 'Back to the sign-in page', 'url' => PublicUrl::account('sign-in')],
        ]);
    }

    private function emailRequestPage(Request $request, string $action, string $path, string $title, string $submit, string $intro, ?string $error = null, string $email = '', int $status = 200): Response
    {
        $form = $this->form($action, PublicUrl::account($path), $submit, [
            $this->field('email', 'Email address', 'email', $email, 'email'),
        ], '', $error === null ? [] : [$error]);

        return $this->page($request, $title, $path, [$form], $status, [], [['label' => 'Back to the sign-in page', 'url' => PublicUrl::account('sign-in')]], false, $intro);
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param list<string> $errors
     * @return array<string, mixed>
     */
    private function form(string $action, string $url, string $submit, array $fields, string $heading = '', array $errors = []): array
    {
        return [
            'id' => $action,
            'action' => $url,
            'csrf_token' => Csrf::token($action),
            'submit' => $submit,
            'heading' => $heading,
            'fields' => $fields,
            'errors' => array_map(static fn (string $message): array => ['message' => $message], $errors),
        ];
    }

    /**
     * @param list<array{value: string, label: string, selected: bool}> $options
     * @return array<string, mixed>
     */
    private function field(string $name, string $label, string $type, string $value, string $autocomplete, string $hint = '', string $error = '', array $options = []): array
    {
        return [
            'name' => $name,
            'id' => 'lf-field-' . str_replace('_', '-', $name),
            'label' => $label,
            'type' => $type,
            'value' => $type === 'password' ? '' : $value,
            'autocomplete' => $autocomplete,
            'hint' => $hint,
            'error' => $error,
            'options' => $options,
        ];
    }

    /**
     * @param list<array<string, mixed>> $forms
     * @param list<array{type: string, text: string}> $notices
     * @param list<array{label: string, url: string}> $links
     * @param list<array{label: string, url: string}> $shortcuts links drawn above the forms, for pages a signed-in member uses
     */
    private function page(Request $request, string $title, string $variant, array $forms, int $status, array $notices, array $links, bool $secretLink = false, string $intro = '', array $shortcuts = []): Response
    {
        foreach ($this->flash->pull($request->cookies) as $message) {
            $notices[] = ['type' => $message['type'], 'text' => $message['message']];
        }

        $response = $this->themes->render('account', [
            'intro' => $intro,
            'notices' => $notices,
            'forms' => $forms,
            'links' => $links,
            'shortcuts' => $shortcuts,
        ], $status, ['title' => $title, 'noindex' => true, 'variant' => $variant]);

        // Every page here is private to one visitor. A page reached through an emailed link also must not pass that
        // link on in a Referer header.
        $response = $response->withHeader('Cache-Control', 'private, no-store');

        return $secretLink ? $response->withHeader('Referrer-Policy', 'no-referrer') : $response;
    }

    private function registrationOpen(): bool
    {
        return $this->config->setting('registration.enabled', true) === true;
    }

    private function validToken(Request $request, string $action): bool
    {
        return Csrf::verify($action, $request->input('csrf_token'), $request->cookies);
    }
}
