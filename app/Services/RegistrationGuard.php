<?php

/**
 * Decides whether a registration form submission may go on to create an account.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Security\RegistrationThrottle;

/**
 * The order matters. The pause and the rate limits come first and count every submission, so probing with bad input
 * costs a bot its allowance. The hidden checks that answer silently come next, then the mistakes a real visitor can fix,
 * then the checks that need a network call, which are left to last so a refused sign-up never reaches Akismet. Nothing
 * here depends on whether an address already has an account, so the answers never reveal who is registered.
 */
final class RegistrationGuard
{
    public const EMAIL_REFUSED = 'We cannot accept sign-ups from that email address. Please use a different one.';

    public const WRONG_ANSWER = 'That answer was not right. Please try again.';

    public function __construct(
        private readonly RegistrationThrottle $throttle,
        private readonly FormGuard $forms,
        private readonly SecurityEventLog $events,
        private readonly SpamSettings $settings,
        private readonly AkismetClient $akismet,
        private readonly HookManager $hooks,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * The question to put to a visitor, or an empty string when none is set.
     */
    public function question(): string
    {
        $rules = $this->settings->signupRules();

        return $rules->asksQuestion() ? $this->settings->current()['question'] : '';
    }

    public function check(Request $request, string $displayName, string $email, string $answer): GuardDecision
    {
        $ip = $request->ip();

        $paused = $this->throttle->secondsPaused();

        if ($paused > 0) {
            $this->events->recordOnce(SecurityEventLog::REGISTRATION_PAUSED, $ip, 'refused while paused');

            return GuardDecision::paused($paused);
        }

        $wait = $this->throttle->secondsUntilAllowed($ip);

        if ($wait > 0) {
            $this->events->recordOnce(SecurityEventLog::REGISTRATION_RATE_LIMITED, $ip);

            return GuardDecision::limited($wait);
        }

        $this->throttle->recordAttempt($ip);

        if ($this->throttle->tripIfFlooded()) {
            $this->events->record(SecurityEventLog::REGISTRATION_PAUSED, $ip, 'flood limit reached');
            $this->audit->record(null, 'security.registration_paused', 'settings', 'spam', [], $ip);

            return GuardDecision::paused($this->throttle->secondsPaused());
        }

        $form = $this->forms->inspect($request, 'account_register');

        if (!$form->allowed()) {
            return $form;
        }

        $rules = $this->settings->signupRules();
        $problem = $rules->emailProblem(strtolower($email));

        if ($problem !== null) {
            $this->events->record(SecurityEventLog::REGISTRATION_BLOCKED_EMAIL, $ip, $problem);

            return GuardDecision::reject(['email' => self::EMAIL_REFUSED]);
        }

        if (!$rules->answerIsRight($answer)) {
            $this->events->record(SecurityEventLog::REGISTRATION_WRONG_ANSWER, $ip);

            return GuardDecision::reject(['challenge' => self::WRONG_ANSWER]);
        }

        $reason = $this->spamReason($request, $displayName, $email);

        if ($reason !== null) {
            $this->events->record(SecurityEventLog::REGISTRATION_SPAM, $ip, $reason);

            return GuardDecision::silent();
        }

        return GuardDecision::allow();
    }

    /**
     * A plugin may add a reason to refuse a sign-up silently. Akismet is only asked when it is on, has a key, and
     * nothing earlier refused the sign-up.
     */
    private function spamReason(Request $request, string $displayName, string $email): ?string
    {
        $reasons = $this->hooks->applyFilters('lf_registration_spam_reasons', [], [
            'display_name' => $displayName,
            'email' => $email,
            'ip' => $request->ip(),
        ]);

        foreach (is_array($reasons) ? $reasons : [] as $reason) {
            if (is_string($reason) && $reason !== '') {
                return 'plugin: ' . $reason;
            }
        }

        $current = $this->settings->current();

        if ($current['akismet_enabled'] && $current['akismet_key'] !== '') {
            $userAgent = $request->server['HTTP_USER_AGENT'] ?? '';

            // A null answer means Akismet could not be asked, which must never stop a real person joining.
            if ($this->akismet->isSpamSignup($current['akismet_key'], $displayName, $email, $request->ip(), is_string($userAgent) ? $userAgent : '') === true) {
                return 'akismet';
            }
        }

        return null;
    }
}
