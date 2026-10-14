<?php

/**
 * The outcome of a sign-in attempt.
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

use LumoraFiction\Models\User;

final readonly class LoginResult
{
    private function __construct(
        public LoginOutcome $outcome,
        public ?User $user = null,
        public int $retryAfterSeconds = 0,
    ) {
    }

    public static function success(User $user): self
    {
        return new self(LoginOutcome::Success, $user);
    }

    public static function invalidCredentials(): self
    {
        return new self(LoginOutcome::InvalidCredentials);
    }

    public static function locked(int $retryAfterSeconds): self
    {
        return new self(LoginOutcome::Locked, retryAfterSeconds: $retryAfterSeconds);
    }

    public static function emailNotVerified(): self
    {
        return new self(LoginOutcome::EmailNotVerified);
    }

    public static function suspended(): self
    {
        return new self(LoginOutcome::Suspended);
    }

    public function succeeded(): bool
    {
        return $this->outcome === LoginOutcome::Success;
    }

    /**
     * The message to show. A wrong login name and a wrong password share one message, so the form
     * never confirms which accounts exist. The other outcomes are only reachable by someone who
     * already supplied the correct password, so telling them the truth leaks nothing.
     */
    public function message(): string
    {
        return match ($this->outcome) {
            LoginOutcome::Success => '',
            LoginOutcome::InvalidCredentials => 'The login name or password is not correct.',
            LoginOutcome::Locked => 'Too many failed attempts. Please try again later.',
            LoginOutcome::EmailNotVerified => 'Please confirm your email address first. Check your inbox for the confirmation link.',
            LoginOutcome::Suspended => 'This account has been suspended.',
        };
    }
}
