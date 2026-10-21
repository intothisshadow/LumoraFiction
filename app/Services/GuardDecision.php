<?php

/**
 * The answer of the sign-up and form protections, with what to tell the visitor.
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

final readonly class GuardDecision
{
    /**
     * @param array<string, string> $errors messages by field name; "form" is for the whole form
     * @param int $retryAfter seconds to wait, for a limited or paused decision
     */
    private function __construct(
        public GuardOutcome $outcome,
        public array $errors = [],
        public int $retryAfter = 0,
    ) {
    }

    public static function allow(): self
    {
        return new self(GuardOutcome::Allow);
    }

    /**
     * @param array<string, string> $errors
     */
    public static function reject(array $errors): self
    {
        return new self(GuardOutcome::Reject, $errors);
    }

    public static function silent(): self
    {
        return new self(GuardOutcome::Silent);
    }

    public static function limited(int $retryAfter): self
    {
        return new self(GuardOutcome::Limited, [], max(1, $retryAfter));
    }

    public static function paused(int $retryAfter): self
    {
        return new self(GuardOutcome::Paused, [], max(1, $retryAfter));
    }

    public function allowed(): bool
    {
        return $this->outcome === GuardOutcome::Allow;
    }
}
