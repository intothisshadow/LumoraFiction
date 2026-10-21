<?php

/**
 * Settings › Spam protection: the limits, lists and checks that guard registration and public forms.
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
use LumoraFiction\Core\Security\RegistrationThrottle;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AkismetClient;
use LumoraFiction\Services\AuditLogService;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\SpamSettings;

final class SpamSettingsController extends AdminController
{
    private const PATH = 'settings/spam';

    private const FIELDS = ['min_seconds', 'gap_seconds', 'ip_limit', 'flood_limit', 'pause_minutes', 'retention_days', 'domain_blocklist', 'question', 'answer', 'akismet_key'];

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly SpamSettings $settings,
        private readonly RegistrationThrottle $throttle,
        private readonly AkismetClient $akismet,
        private readonly AuditLogService $audit,
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

        $submitted = ['block_disposable' => $request->checked('block_disposable'), 'akismet_enabled' => $request->checked('akismet_enabled')];

        foreach (self::FIELDS as $field) {
            $submitted[$field] = $request->input($field);
        }

        $result = $this->settings->validate($submitted);

        if (!$this->validToken($request, 'admin_settings_spam')) {
            return $this->page($request, $user, $result['values'], ['form' => 'Your session expired, so nothing was saved. Please check the settings and save again.'], 400);
        }

        $errors = $result['errors'];
        $current = $this->settings->current();
        $note = '';

        // The key is only asked about when it is new or has just been turned on, so saving other settings stays quick.
        if (!isset($errors['akismet_key']) && $result['values']['akismet_enabled'] === true && ($current['akismet_enabled'] !== true || $current['akismet_key'] !== $result['values']['akismet_key'])) {
            $accepted = $this->akismet->verifyKey((string) $result['values']['akismet_key']);

            if ($accepted === false) {
                $errors['akismet_key'] = 'Akismet does not accept that key. Check it on your Akismet account page.';
            } elseif ($accepted === null) {
                $note = ' Akismet could not be reached to check the key, so it will be tried when someone registers.';
            }
        }

        if ($errors !== []) {
            return $this->page($request, $user, $result['values'], $errors, 422);
        }

        $changed = $this->settings->save($result['values'], $user, $request->ip());

        $this->flash->add('success', ($changed === [] ? 'Nothing needed changing.' : 'Settings saved.') . $note);

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function resume(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, 'admin_settings_spam_resume')) {
            $this->flash->add('error', 'Your session expired, so nothing was changed. Please try again.');
        } elseif ($this->throttle->secondsPaused() > 0) {
            $this->throttle->resume();
            $this->audit->record($user->id, 'security.registration_resumed', 'settings', 'spam', [], $request->ip());
            $this->flash->add('success', 'Registration is open again.');
        } else {
            $this->flash->add('success', 'Registration was not paused.');
        }

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function page(Request $request, User $user, array $values, array $errors, int $status = 200): Response
    {
        return $this->view->render('settings-spam', [
            'values' => $values,
            'errors' => $errors,
            'pausedFor' => $this->throttle->secondsPaused(),
            'csrfToken' => Csrf::token('admin_settings_spam'),
            'resumeToken' => Csrf::token('admin_settings_spam_resume'),
            'action' => AdminUrl::to(self::PATH),
            'resumeAction' => AdminUrl::to(self::PATH . '/resume'),
            'eventsUrl' => AdminUrl::to('maintenance/security-events'),
        ], $user, 'Spam protection', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
