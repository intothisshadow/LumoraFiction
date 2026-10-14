<?php

/**
 * Locks an address out of signing in after repeated failures.
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

use LumoraFiction\Core\Database\Database;
use Throwable;

/**
 * Keyed by IP address and stored in the database, because an attacker hammering the form does not
 * necessarily carry cookies, so a session counter would never engage. The lockout decision is
 * IP-only, which resists an attack that rotates through account names.
 *
 * Failures are deliberately not cleared by a successful sign-in. Otherwise an attacker holding any
 * valid account could interleave guesses at someone else's account with logins to their own and
 * never reach the limit; failures simply age out of the window instead.
 */
final class LoginThrottle
{
    // How far back repeat lockouts are remembered when a lockout is lengthened.
    private const REPEAT_HISTORY_SECONDS = 86400;

    private const MAX_LOCKOUT_MULTIPLIER = 16;

    public function __construct(
        private readonly Database $database,
        private readonly int $maxAttempts = 5,
        private readonly int $windowSeconds = 900,
        private readonly int $lockoutSeconds = 900,
        private readonly bool $progressive = false,
    ) {
    }

    /**
     * Seconds remaining before this address may try again, or 0 when it is not locked out.
     *
     * Fails open on a database error: briefly losing brute-force protection is better than locking
     * every administrator out of a working site. The error is still logged.
     */
    public function secondsUntilUnlocked(string $ipAddress): int
    {
        try {
            $summary = $this->database->fetchOne(
                'SELECT COUNT(*) AS history_attempts, MAX(attempted_at) AS last_attempt_at
                 FROM {prefix}login_attempts
                 WHERE ip_address = :ip AND attempted_at >= :history_start',
                ['ip' => $ipAddress, 'history_start' => date('Y-m-d H:i:s', time() - max($this->windowSeconds, self::REPEAT_HISTORY_SECONDS))],
            );

            $lastAttemptAt = $summary['last_attempt_at'] ?? null;

            if ($lastAttemptAt === null) {
                return 0;
            }

            // Counted back from the last attempt, not from now, so a lockout longer than the window
            // is not cut short as the failures that caused it age out.
            $attempts = (int) $this->database->fetchColumn(
                'SELECT COUNT(*) FROM {prefix}login_attempts WHERE ip_address = :ip AND attempted_at >= :burst_start',
                ['ip' => $ipAddress, 'burst_start' => date('Y-m-d H:i:s', strtotime((string) $lastAttemptAt) - $this->windowSeconds)],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return 0;
        }

        if ($attempts < $this->maxAttempts) {
            return 0;
        }

        $lockedUntil = strtotime((string) $lastAttemptAt) + ($this->lockoutSeconds * $this->lockoutMultiplier((int) $summary['history_attempts']));

        return max(0, $lockedUntil - time());
    }

    public function isLocked(string $ipAddress): bool
    {
        return $this->secondsUntilUnlocked($ipAddress) > 0;
    }

    /**
     * Best effort: a failure to write the attempt must never block or alter the sign-in response,
     * which has already been rejected.
     */
    public function recordFailure(string $ipAddress): void
    {
        try {
            $this->database->execute(
                'INSERT INTO {prefix}login_attempts (ip_address, attempted_at) VALUES (:ip, :attempted_at)',
                ['ip' => $ipAddress, 'attempted_at' => date('Y-m-d H:i:s')],
            );

            // Old rows serve no purpose and hold addresses, so they are removed as new ones arrive.
            $this->database->execute(
                'DELETE FROM {prefix}login_attempts WHERE attempted_at < :cutoff',
                ['cutoff' => date('Y-m-d H:i:s', time() - max($this->windowSeconds, self::REPEAT_HISTORY_SECONDS))],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    /**
     * Each further batch of failures from one address within a day doubles the lockout when
     * progressive mode is on, so a patient attacker pays more for every cycle.
     */
    private function lockoutMultiplier(int $historyAttempts): int
    {
        if (!$this->progressive) {
            return 1;
        }

        return min(self::MAX_LOCKOUT_MULTIPLIER, 2 ** (max(1, intdiv($historyAttempts, max(1, $this->maxAttempts))) - 1));
    }

    private function logFailure(Throwable $exception): void
    {
        error_log('[LoginThrottle] ' . $exception::class . ': ' . $exception->getMessage());
    }
}
