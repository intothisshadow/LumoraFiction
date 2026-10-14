<?php

/**
 * Single-use tokens for emailed links, such as email verification and password reset.
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
 * A link carries "selector:secret". The selector finds the row; only a SHA-256 hash of the secret
 * is stored, so a leaked table cannot be turned into working links. A fast hash is correct here
 * because the secret is 256 random bits, not a guessable password. Issuing a token replaces any
 * earlier one for the same user and purpose, so a new request supersedes an unused link.
 */
final class TokenService
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Returns the "selector:secret" value to put in the emailed link, or null when a token was
     * issued within the cooldown (the caller should behave as if it succeeded, since the earlier
     * email is still valid and re-sending would only let someone flood an inbox) or on a database error.
     */
    public function issue(int $userId, string $purpose, int $lifetimeSeconds, int $cooldownSeconds = 0): ?string
    {
        try {
            if ($cooldownSeconds > 0) {
                $lastIssuedAt = $this->database->fetchColumn(
                    'SELECT created_at FROM {prefix}user_tokens WHERE user_id = :user AND purpose = :purpose',
                    ['user' => $userId, 'purpose' => $purpose],
                );

                if (is_string($lastIssuedAt) && (time() - (int) strtotime($lastIssuedAt)) < $cooldownSeconds) {
                    return null;
                }
            }

            $this->database->execute('DELETE FROM {prefix}user_tokens WHERE expires_at < :now', ['now' => date('Y-m-d H:i:s')]);
            $this->database->execute(
                'DELETE FROM {prefix}user_tokens WHERE user_id = :user AND purpose = :purpose',
                ['user' => $userId, 'purpose' => $purpose],
            );

            $selector = bin2hex(random_bytes(12));
            $secret = bin2hex(random_bytes(32));

            $this->database->execute(
                'INSERT INTO {prefix}user_tokens (user_id, purpose, selector, token_hash, expires_at, created_at)
                 VALUES (:user, :purpose, :selector, :hash, :expires, :created)',
                [
                    'user' => $userId,
                    'purpose' => $purpose,
                    'selector' => $selector,
                    'hash' => hash('sha256', $secret),
                    'expires' => date('Y-m-d H:i:s', time() + $lifetimeSeconds),
                    'created' => date('Y-m-d H:i:s'),
                ],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return null;
        }

        return $selector . ':' . $secret;
    }

    /**
     * Checks a token without using it up. A link that a mail scanner or browser pre-fetches must not
     * burn the token before the person actually submits the form.
     */
    public function peek(string $purpose, string $token): ?int
    {
        return $this->validate($purpose, $token)['userId'] ?? null;
    }

    /**
     * Validates a token and deletes it in one step. Only call this when the action is really being carried out.
     */
    public function consume(string $purpose, string $token): ?int
    {
        $result = $this->validate($purpose, $token);

        if ($result === null) {
            return null;
        }

        try {
            // Deleting by selector and checking the count makes two simultaneous uses of one link race
            // safely: only one of them sees a row removed.
            if ($this->database->execute('DELETE FROM {prefix}user_tokens WHERE selector = :selector', ['selector' => $result['selector']]) !== 1) {
                return null;
            }
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return null;
        }

        return $result['userId'];
    }

    public function invalidateForUser(int $userId, ?string $purpose = null): void
    {
        try {
            if ($purpose === null) {
                $this->database->execute('DELETE FROM {prefix}user_tokens WHERE user_id = :user', ['user' => $userId]);
            } else {
                $this->database->execute(
                    'DELETE FROM {prefix}user_tokens WHERE user_id = :user AND purpose = :purpose',
                    ['user' => $userId, 'purpose' => $purpose],
                );
            }
        } catch (Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    /**
     * @return array{userId: int, selector: string}|null
     */
    private function validate(string $purpose, string $token): ?array
    {
        $parts = explode(':', $token, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '' || strlen($parts[0]) > 24 || strlen($parts[1]) > 128) {
            return null;
        }

        [$selector, $secret] = $parts;

        try {
            $row = $this->database->fetchOne(
                'SELECT user_id, token_hash, expires_at FROM {prefix}user_tokens WHERE selector = :selector AND purpose = :purpose',
                ['selector' => $selector, 'purpose' => $purpose],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return null;
        }

        if ($row === null || strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        if (!hash_equals((string) $row['token_hash'], hash('sha256', $secret))) {
            return null;
        }

        return ['userId' => (int) $row['user_id'], 'selector' => $selector];
    }

    private function logFailure(Throwable $exception): void
    {
        error_log('[TokenService] ' . $exception::class . ': ' . $exception->getMessage());
    }
}
