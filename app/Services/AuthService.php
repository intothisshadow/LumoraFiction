<?php

/**
 * Registration, sign-in, sign-out, email verification, and password reset.
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

use InvalidArgumentException;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Mail\Mailer;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Core\Security\LoginThrottle;
use LumoraFiction\Core\Security\PasswordPolicy;
use LumoraFiction\Core\Security\SessionManager;
use LumoraFiction\Core\Security\TokenService;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Models\UserRole;
use LumoraFiction\Models\UserStatus;
use RuntimeException;

/**
 * Emailed links are built from the configured site URL, never from the request's Host header. A
 * forged Host header would otherwise let an attacker have a victim's password-reset link point at
 * a server the attacker controls.
 */
final class AuthService
{
    /** Settings this service reads, with the secure value as the default. */
    public const DEFAULTS = [
        'registration.enabled' => true,
        'registration.default_role' => 'author',
        'registration.email_verification' => true,
    ];

    public const PURPOSE_VERIFY_EMAIL = 'verify_email';

    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    private const VERIFY_LIFETIME_SECONDS = 172800;

    private const RESET_LIFETIME_SECONDS = 3600;

    private const RESEND_COOLDOWN_SECONDS = 60;

    private const LOCKOUT_NOTICE_COOLDOWN_SECONDS = 3600;

    private const SESSION_USER = 'auth_user_id';

    private const SESSION_STARTED_AT = 'auth_started_at';

    private const INVALID_RESET_LINK = 'This password reset link is invalid or has expired.';

    private ?User $currentUser = null;

    private bool $resolved = false;

    private readonly string $siteUrl;

    public function __construct(
        private readonly UserService $users,
        private readonly SessionManager $sessions,
        private readonly TokenService $tokens,
        private readonly Mailer $mailer,
        private readonly LoginThrottle $throttle,
        private readonly PasswordPolicy $passwords,
        private readonly Configuration $config,
        string $siteUrl,
        private readonly string $siteName = 'Lumora Fiction',
        private readonly ?SecurityEventLog $events = null,
    ) {
        $siteUrl = rtrim($siteUrl, '/');

        if ($siteUrl !== '' && preg_match('#^https?://[^\s/?\#]+(/[^\s?\#]*)?\z#', $siteUrl) !== 1) {
            throw new InvalidArgumentException('The site URL must be an http or https address.');
        }

        $this->siteUrl = $siteUrl;
    }

    public function register(string $loginName, string $displayName, string $email, string $password): RegistrationResult
    {
        if ($this->config->setting('registration.enabled', true) !== true) {
            return RegistrationResult::rejected(['form' => 'Registration is closed.']);
        }

        $errors = $this->users->validateNewAccount($loginName, $displayName, $email, $password);

        if ($errors !== []) {
            return RegistrationResult::rejected($errors);
        }

        $needsVerification = $this->config->setting('registration.email_verification', true) !== false;

        // Checked before anything is saved so a missing site URL cannot leave an account that can never be confirmed.
        if ($needsVerification) {
            $this->requireSiteUrl();
        }

        $displayName = AccountRules::normalizeDisplayName($displayName);
        $email = AccountRules::normalizeEmail($email);

        if (($existing = $this->users->findByEmail($email)) !== null) {
            // Hashed and thrown away so this path takes as long as creating an account does.
            password_hash($password, PASSWORD_DEFAULT);

            $this->sendAlreadyRegisteredNotice($existing);

            return RegistrationResult::accepted($needsVerification);
        }

        try {
            $user = $this->users->create(
                $loginName,
                $displayName,
                $email,
                $password,
                $this->defaultRole(),
                $needsVerification ? UserStatus::Pending : UserStatus::Active,
            );
        } catch (DuplicateAccountException $exception) {
            if ($exception->field === 'login name') {
                return RegistrationResult::rejected(['login_name' => 'That login name is already taken.']);
            }

            // The address was registered between the check above and the save; treat it the same way.
            return RegistrationResult::accepted($needsVerification);
        }

        if ($needsVerification) {
            $this->sendVerification($user, 0);
        }

        return RegistrationResult::accepted($needsVerification);
    }

    /**
     * Confirms an email address from an emailed link and activates a pending account.
     */
    public function verifyEmail(string $token): bool
    {
        $userId = $this->tokens->consume(self::PURPOSE_VERIFY_EMAIL, $token);

        if ($userId === null) {
            return false;
        }

        $this->users->markEmailVerified($userId);

        return true;
    }

    /**
     * Sends a fresh confirmation link to a pending account. Always behaves the same whether or not
     * the address is registered, so it cannot be used to find out who has an account.
     */
    public function resendVerification(string $email): void
    {
        // Checked before the lookup: failing only for registered addresses would reveal which exist.
        $this->requireSiteUrl();

        $user = $this->users->findByEmail($email);

        if ($user !== null && $user->status === UserStatus::Pending) {
            $this->sendVerification($user, self::RESEND_COOLDOWN_SECONDS);
        }
    }

    public function attempt(string $identity, string $password, string $ipAddress): LoginResult
    {
        $remaining = $this->throttle->secondsUntilUnlocked($ipAddress);

        if ($remaining > 0) {
            return LoginResult::locked($remaining);
        }

        $user = $this->users->verifyCredentials($identity, $password);

        if ($user === null) {
            $this->throttle->recordFailure($ipAddress);

            // Only the failure that starts a lockout is noted; the attempts refused while it lasts return above.
            $lockedFor = $this->throttle->secondsUntilUnlocked($ipAddress);

            if ($lockedFor > 0) {
                $this->events?->record(SecurityEventLog::LOGIN_LOCKOUT, $ipAddress);
                $this->warnOwner($identity, $ipAddress, $lockedFor);
            }

            return LoginResult::invalidCredentials();
        }

        if ($user->status === UserStatus::Pending) {
            return LoginResult::emailNotVerified();
        }

        if ($user->status === UserStatus::Suspended) {
            return LoginResult::suspended();
        }

        $this->beginSession($user);

        return LoginResult::success($user);
    }

    /**
     * @param array<string, mixed>|null $cookies the request's cookies; the PHP superglobal when omitted
     */
    public function logout(?array $cookies = null): void
    {
        $this->sessions->startIfExisting($cookies ?? $_COOKIE);
        $this->sessions->destroy();

        $this->currentUser = null;
        $this->resolved = true;
    }

    /**
     * The signed-in account, or null for a guest. The account is read from the database on every
     * request, so suspending it or changing its password takes effect immediately.
     *
     * @param array<string, mixed>|null $cookies the request's cookies; the PHP superglobal when omitted
     */
    public function user(?array $cookies = null): ?User
    {
        if ($this->resolved) {
            return $this->currentUser;
        }

        $this->resolved = true;

        if (!$this->sessions->startIfExisting($cookies ?? $_COOKIE)) {
            return null;
        }

        $id = $_SESSION[self::SESSION_USER] ?? null;
        $startedAt = $_SESSION[self::SESSION_STARTED_AT] ?? null;

        if (!is_int($id) || !is_int($startedAt)) {
            return null;
        }

        $user = $this->users->findById($id);

        // A session that began before the last password change is treated as signed out.
        $stale = $user?->passwordChangedAt !== null && $user->passwordChangedAt->getTimestamp() > $startedAt;

        if ($user === null || $user->status !== UserStatus::Active || $stale) {
            $this->sessions->destroy();

            return null;
        }

        return $this->currentUser = $user;
    }

    /**
     * @param array<string, mixed>|null $cookies
     */
    public function check(?array $cookies = null): bool
    {
        return $this->user($cookies) !== null;
    }

    /**
     * @param array<string, mixed>|null $cookies
     */
    public function can(Capability $capability, ?array $cookies = null): bool
    {
        return $this->user($cookies)?->can($capability) ?? false;
    }

    /**
     * Sends a reset link when the address belongs to an active account. Says nothing either way.
     * Registered addresses take slightly longer to answer because mail is sent; the request limits
     * bound how useful that is, but it is not eliminated without queued delivery.
     */
    public function requestPasswordReset(string $email): void
    {
        // Checked before the lookup: failing only for registered addresses would reveal which exist.
        $this->requireSiteUrl();

        $user = $this->users->findByEmail($email);

        if ($user === null || $user->status === UserStatus::Suspended) {
            return;
        }

        $token = $this->tokens->issue($user->id, self::PURPOSE_PASSWORD_RESET, self::RESET_LIFETIME_SECONDS, self::RESEND_COOLDOWN_SECONDS);

        if ($token === null) {
            return;
        }

        $this->send(
            $user,
            'Reset your password on ' . $this->siteName,
            "Hello {$user->displayName},\n\nSomeone asked to reset the password for your account on {$this->siteName}. "
            . "To choose a new password, open this link within one hour:\n\n"
            . $this->link('/account/reset/' . rawurlencode($token))
            . "\n\nIf you did not ask for this, you can ignore this email and your password will stay the same.\n",
        );
    }

    /**
     * Checks a reset link without using it up, so a mail scanner that opens the link does not spoil it.
     */
    public function passwordResetLinkIsValid(string $token): bool
    {
        return $this->tokens->peek(self::PURPOSE_PASSWORD_RESET, $token) !== null;
    }

    /**
     * @return string|null a plain-language problem, or null when the password was changed
     */
    public function resetPassword(string $token, string $newPassword): ?string
    {
        $userId = $this->tokens->peek(self::PURPOSE_PASSWORD_RESET, $token);
        $user = $userId === null ? null : $this->users->findById($userId);

        if ($user === null || $user->status === UserStatus::Suspended) {
            return self::INVALID_RESET_LINK;
        }

        $problem = $this->passwords->violation($newPassword, [$user->loginName, $user->displayName, $user->email]);

        if ($problem !== null) {
            return $problem;
        }

        // Only one of two simultaneous uses of the same link gets past this point.
        if ($this->tokens->consume(self::PURPOSE_PASSWORD_RESET, $token) === null) {
            return self::INVALID_RESET_LINK;
        }

        $this->users->updatePassword($user->id, $newPassword);

        // Following an emailed link proves control of the address, so a pending account is confirmed too.
        $this->users->markEmailVerified($user->id);
        $this->tokens->invalidateForUser($user->id);

        return null;
    }

    /**
     * Changes a signed-in person's password after they prove they know the current one. Every other session ends,
     * because it began before the change; this one is started again so the person stays signed in.
     *
     * @return string|null a plain-language problem, or null when the password was changed
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword, string $ipAddress): ?string
    {
        $problem = $this->confirmPassword($user, $currentPassword, $ipAddress)
            ?? $this->passwords->violation($newPassword, [$user->loginName, $user->displayName, $user->email]);

        if ($problem !== null) {
            return $problem;
        }

        $this->users->updatePassword($user->id, $newPassword);
        $this->tokens->invalidateForUser($user->id, self::PURPOSE_PASSWORD_RESET);

        $this->beginSession($this->users->findById($user->id) ?? $user);

        return null;
    }

    /**
     * Changes the address after the person proves they know the password. The old address is told, so a stolen
     * session cannot quietly move an account somewhere its owner will not hear about.
     *
     * @return string|null a plain-language problem, or null when the address was changed
     */
    public function changeEmail(User $user, string $currentPassword, string $email, string $ipAddress): ?string
    {
        $email = AccountRules::normalizeEmail($email);
        $problem = AccountRules::emailProblem($email) ?? $this->confirmPassword($user, $currentPassword, $ipAddress);

        if ($problem !== null) {
            return $problem;
        }

        if ($email === $user->email) {
            return 'That is already the address on this account.';
        }

        try {
            $this->users->updateEmail($user->id, $email);
        } catch (DuplicateAccountException) {
            return 'That email address cannot be used.';
        }

        $this->send(
            $user,
            'The email address on your ' . $this->siteName . ' account was changed',
            "Hello {$user->displayName},\n\nThe email address on your account on {$this->siteName} was just changed. "
            . "If you did this, no action is needed.\n\nIf you did not, reset your password from the sign-in page right away.\n",
        );

        return null;
    }

    /**
     * Checks a password the person typed to authorise a change to their own account. Wrong guesses count towards
     * the same lockout as the sign-in form, so a stolen session cannot be used to guess the password.
     */
    private function confirmPassword(User $user, string $password, string $ipAddress): ?string
    {
        if ($this->throttle->secondsUntilUnlocked($ipAddress) > 0) {
            return 'Too many failed attempts. Please try again later.';
        }

        if ($this->users->verifyCredentials($user->loginName, $password)?->id !== $user->id) {
            $this->throttle->recordFailure($ipAddress);

            return 'Your current password is not correct.';
        }

        return null;
    }

    private function beginSession(User $user): void
    {
        $this->sessions->start();

        // A new identifier on every change of privilege prevents session fixation, and outstanding
        // CSRF tokens issued to the previous identity are discarded.
        $this->sessions->regenerate();
        Csrf::regenerate();

        $_SESSION[self::SESSION_USER] = $user->id;
        $_SESSION[self::SESSION_STARTED_AT] = time();

        $this->currentUser = $user;
        $this->resolved = true;
    }

    private function defaultRole(): UserRole
    {
        $setting = $this->config->setting('registration.default_role', 'author');
        $role = is_string($setting) ? UserRole::tryFrom($setting) : null;

        // Staff roles can only be given by an administrator, whatever the setting says.
        return $role !== null && in_array($role, UserRole::selfServiceRoles(), true) ? $role : UserRole::Reader;
    }

    private function sendVerification(User $user, int $cooldownSeconds): void
    {
        $token = $this->tokens->issue($user->id, self::PURPOSE_VERIFY_EMAIL, self::VERIFY_LIFETIME_SECONDS, $cooldownSeconds);

        if ($token === null) {
            return;
        }

        $this->send(
            $user,
            'Confirm your email address for ' . $this->siteName,
            "Hello {$user->displayName},\n\nThank you for joining {$this->siteName}. "
            . "To confirm your email address and activate your account, open this link within two days:\n\n"
            . $this->link('/account/verify/' . rawurlencode($token))
            . "\n\nIf you did not create this account, you can ignore this email.\n",
        );
    }

    /**
     * Tells the owner of the account the failing sign-in named that someone is guessing at it. The page the visitor sees
     * is the same whether or not an account was named, so this reveals nothing to the one who is guessing. Only an
     * account that can sign in is written to, at most once an hour, so a flood of lockouts cannot flood an inbox.
     */
    private function warnOwner(string $identity, string $ipAddress, int $lockedFor): void
    {
        if ($this->config->setting('security.notify_owner', true) === false || $this->siteUrl === '') {
            return;
        }

        $user = $this->users->findByIdentity($identity);

        if ($user === null || $user->status !== UserStatus::Active || !$this->users->claimLockoutNotice($user->id, self::LOCKOUT_NOTICE_COOLDOWN_SECONDS)) {
            return;
        }

        $minutes = max(1, (int) ceil($lockedFor / 60));

        $this->send(
            $user,
            'Someone keeps trying to sign in to your ' . $this->siteName . ' account',
            "Hello {$user->displayName},\n\nSomeone has typed a wrong password for your account on {$this->siteName} several times in a row, "
            . "from the internet address {$ipAddress}. Sign-in from that address is paused for about {$minutes} " . ($minutes === 1 ? 'minute' : 'minutes') . ".\n\n"
            . "If that was you, you can ignore this message. If it was not, nobody has got in and nothing has changed, but it is a good time to choose a long password you do not use anywhere else:\n\n"
            . $this->link('/account/forgot') . "\n",
        );
    }

    private function sendAlreadyRegisteredNotice(User $user): void
    {
        $this->send(
            $user,
            'Someone tried to register your email address on ' . $this->siteName,
            "Hello {$user->displayName},\n\nSomeone tried to create a new account on {$this->siteName} using this email address, "
            . "which already has an account. If that was you, you can sign in, or reset your password from the sign-in page.\n\n"
            . "If it was not you, no action is needed and nothing has changed.\n",
        );
    }

    private function send(User $user, string $subject, string $body): void
    {
        if (!$this->mailer->send($user->email, $subject, $body)) {
            // Never log the address or the link: both are personal, and the link is a credential.
            error_log('[AuthService] An email could not be handed to the mail transport.');
        }
    }

    private function link(string $path): string
    {
        return $this->siteUrl . $path;
    }

    private function requireSiteUrl(): void
    {
        if ($this->siteUrl === '') {
            throw new RuntimeException('The site URL is not configured, so emailed links cannot be created.');
        }
    }
}
