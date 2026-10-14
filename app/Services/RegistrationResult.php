<?php

/**
 * The outcome of a registration attempt.
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

final readonly class RegistrationResult
{
    /**
     * @param array<string, string> $errors field name => problem
     */
    private function __construct(
        public bool $accepted,
        public array $errors = [],
        public bool $needsVerification = false,
    ) {
    }

    /**
     * Whether the person is told to check their email or can sign in straight away. When an
     * address was already registered this looks identical, because the form must not reveal who has an account.
     */
    public static function accepted(bool $needsVerification): self
    {
        return new self(true, [], $needsVerification);
    }

    /**
     * @param array<string, string> $errors
     */
    public static function rejected(array $errors): self
    {
        return new self(false, $errors);
    }
}
