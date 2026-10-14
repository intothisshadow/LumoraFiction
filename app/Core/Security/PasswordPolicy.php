<?php

/**
 * Decides whether a password is acceptable.
 *
 * @package LumoraFiction
 * @subpackage Security
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Security;

use LumoraFiction\Core\Configuration;

/**
 * Length rules only, on purpose: composition requirements and forced expiry push people toward
 * predictable passwords. The administrator can raise the minimum but never lower it below the
 * floor, and a changed minimum never affects passwords that are already set. The upper limit exists
 * because bcrypt silently ignores everything after 72 bytes, so a longer password would give a false
 * sense of strength.
 */
final class PasswordPolicy
{
    public const SETTING = 'security.password_min_length';

    public const DEFAULT_MIN_LENGTH = 10;

    public const FLOOR_MIN_LENGTH = 8;

    public const CEILING_MIN_LENGTH = 64;

    public const MAX_BYTES = 72;

    public function __construct(private readonly Configuration $config)
    {
    }

    public function minLength(): int
    {
        $stored = $this->config->setting(self::SETTING, self::DEFAULT_MIN_LENGTH);

        return min(self::CEILING_MIN_LENGTH, max(self::FLOOR_MIN_LENGTH, is_numeric($stored) ? (int) $stored : self::DEFAULT_MIN_LENGTH));
    }

    /**
     * @param list<string> $related values the password must not simply repeat, such as the account's names and email
     * @return string|null a plain-language reason the password is not acceptable, or null when it is
     */
    public function violation(string $password, array $related = []): ?string
    {
        if (mb_strlen($password) < $this->minLength()) {
            return sprintf('Password must be at least %d characters long.', $this->minLength());
        }

        if (strlen($password) > self::MAX_BYTES) {
            return sprintf('Password must be no longer than %d bytes (about %d characters).', self::MAX_BYTES, self::MAX_BYTES);
        }

        foreach ($related as $value) {
            if ($value !== '' && mb_strtolower($password) === mb_strtolower($value)) {
                return 'Password must not be the same as your name or email address.';
            }
        }

        return null;
    }
}
