<?php

/**
 * Per-address rate limits on the registration form, and a pause for the whole site when a flood arrives.
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
use LumoraFiction\Core\Database\Database;
use Throwable;

/**
 * A botnet rotates addresses, so a limit per address cannot stop a flood alone; the second layer counts every
 * submission from every address and pauses registration once the total passes a limit. Every submission is counted
 * whether or not it was valid, so probing with bad input costs a bot its allowance just as a good sign-up does.
 *
 * Fails open on a database error, like the sign-in throttle, so a broken table cannot lock real visitors out. The error
 * is still logged. A limit of 0 turns that limit off.
 */
final class RegistrationThrottle
{
    private const WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly Database $database,
        private readonly Configuration $config,
    ) {
    }

    /**
     * Seconds this address must wait, from the short gap or the hourly cap, whichever is longer; 0 when it may submit.
     */
    public function secondsUntilAllowed(string $ipAddress): int
    {
        $gap = $this->number('spam.registration_gap_seconds');
        $limit = $this->number('spam.registration_ip_limit');

        if ($gap < 1 && $limit < 1) {
            return 0;
        }

        try {
            $times = $this->database->fetchAll(
                'SELECT attempted_at FROM {prefix}registration_attempts WHERE ip_address = :ip AND attempted_at >= :since ORDER BY attempted_at DESC LIMIT ' . max(1, $limit),
                ['ip' => $ipAddress, 'since' => date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS)],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return 0;
        }

        if ($times === []) {
            return 0;
        }

        $wait = $gap > 0 ? $gap - (time() - strtotime((string) $times[0]['attempted_at'])) : 0;

        // The limit-th most recent submission is the one whose ageing out frees a place.
        if ($limit > 0 && count($times) >= $limit) {
            $wait = max($wait, strtotime((string) $times[$limit - 1]['attempted_at']) + self::WINDOW_SECONDS - time());
        }

        return max(0, $wait);
    }

    public function recordAttempt(string $ipAddress): void
    {
        try {
            $this->database->execute(
                'INSERT INTO {prefix}registration_attempts (ip_address, attempted_at) VALUES (:ip, :attempted_at)',
                ['ip' => $ipAddress, 'attempted_at' => date('Y-m-d H:i:s')],
            );

            // Old rows serve no purpose, and a flood would otherwise keep them for ever.
            $this->database->execute(
                'DELETE FROM {prefix}registration_attempts WHERE attempted_at < :cutoff',
                ['cutoff' => date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS * 2)],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    /**
     * Seconds left of a site-wide pause, or 0 when registration is open.
     */
    public function secondsPaused(): int
    {
        return max(0, $this->number('spam.registration_paused_until') - time());
    }

    /**
     * Pauses registration when the last hour's total has reached the flood limit.
     *
     * @return bool true only on the call that starts the pause, so the caller records and announces it once
     */
    public function tripIfFlooded(): bool
    {
        $limit = $this->number('spam.registration_flood_limit');

        if ($limit < 1 || $this->secondsPaused() > 0) {
            return false;
        }

        try {
            $total = (int) $this->database->fetchColumn(
                'SELECT COUNT(*) FROM {prefix}registration_attempts WHERE attempted_at >= :since',
                ['since' => date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS)],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return false;
        }

        if ($total < $limit) {
            return false;
        }

        $this->config->setSetting('spam.registration_paused_until', time() + max(1, $this->number('spam.registration_pause_minutes')) * 60);
        $this->forgetRecentAttempts();

        return true;
    }

    public function resume(): void
    {
        $this->config->setSetting('spam.registration_paused_until', 0);
        $this->forgetRecentAttempts();
    }

    /**
     * The count that tripped the pause would trip it again the moment the pause ended, and would hold back visitors
     * who did nothing wrong, so the count starts over. A flood that carries on trips the pause again after the same
     * number of new submissions.
     */
    private function forgetRecentAttempts(): void
    {
        try {
            $this->database->execute('DELETE FROM {prefix}registration_attempts WHERE attempted_at >= :since', ['since' => date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS)]);
        } catch (Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    private function number(string $name): int
    {
        $value = $this->config->setting($name, 0);

        return is_int($value) ? max(0, $value) : 0;
    }

    private function logFailure(Throwable $exception): void
    {
        error_log('[RegistrationThrottle] ' . $exception::class . ': ' . $exception->getMessage());
    }
}
