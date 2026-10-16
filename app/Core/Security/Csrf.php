<?php

/**
 * Per-action, single-use CSRF tokens stored in the session.
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

use RuntimeException;

/**
 * Tokens are keyed by an action name so several forms on a page do not collide. Each action keeps
 * a short pool of live tokens rather than one, so a story left open in several tabs does not have
 * its token invalidated by the most recently loaded tab. A token is spent when verified, whether or
 * not it turns out to be stale. Static because templates need to emit a token without a container.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_tokens';

    private const MAX_TOKENS_PER_ACTION = 30;

    // Long enough for an editor left open through a working day, short enough that a token leaked
    // into an old log or browser history is worthless.
    private const TOKEN_LIFETIME_SECONDS = 43200;

    private static ?SessionManager $sessions = null;

    private static bool $issuedThisRequest = false;

    /** @var (callable(string): void)|null */
    private static $failureListener = null;

    /**
     * Lets a token be requested before the session was started: the first token starts it.
     */
    public static function useSessions(?SessionManager $sessions): void
    {
        self::$sessions = $sessions;
    }

    /**
     * @param (callable(string): void)|null $listener called with the action name whenever verification fails
     */
    public static function setFailureListener(?callable $listener): void
    {
        self::$failureListener = $listener;
    }

    /**
     * Whether a token was issued during this request. A page that embeds a token belongs to one
     * visitor's session, so a shared cache must never store it.
     */
    public static function wasIssued(): bool
    {
        return self::$issuedThisRequest;
    }

    public static function token(string $action = 'default'): string
    {
        self::ensureSession(true);
        self::$issuedThisRequest = true;

        $pool = self::pool($action);
        $pool[] = ['token' => bin2hex(random_bytes(32)), 'issued_at' => time()];
        $_SESSION[self::SESSION_KEY][$action] = array_slice($pool, -self::MAX_TOKENS_PER_ACTION);

        return $pool[array_key_last($pool)]['token'];
    }

    /**
     * @param array<string, mixed>|null $cookies the request's cookies, used to open the visitor's
     *                                           existing session; the PHP superglobal when omitted
     */
    public static function verify(string $action, ?string $token, ?array $cookies = null): bool
    {
        // The session is opened only if the visitor already has one: a request with none cannot
        // hold a valid token, and checking a token must never create a session.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::$sessions?->startIfExisting($cookies ?? $_COOKIE);
        }

        if ($token !== null && $token !== '' && self::ensureSession(false)) {
            $pool = self::pool($action);

            foreach ($pool as $index => $entry) {
                if (hash_equals($entry['token'], $token)) {
                    unset($pool[$index]);
                    $_SESSION[self::SESSION_KEY][$action] = array_values($pool);

                    return (time() - $entry['issued_at']) <= self::TOKEN_LIFETIME_SECONDS;
                }
            }
        }

        if (self::$failureListener !== null) {
            (self::$failureListener)($action);
        }

        return false;
    }

    /**
     * Discards every outstanding token. Called when the session's privilege level changes, so a
     * token issued to the previous identity cannot be replayed by the new one.
     */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_KEY] = [];
        }
    }

    public static function field(string $action = 'default'): string
    {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(self::token($action), ENT_QUOTES, 'UTF-8')
            . '">';
    }

    /**
     * Forgets static state between requests; used by tests.
     */
    public static function reset(): void
    {
        self::$sessions = null;
        self::$issuedThisRequest = false;
        self::$failureListener = null;
    }

    /**
     * Expired entries are dropped here so they cannot crowd live tokens out of the bounded pool.
     *
     * @return list<array{token: string, issued_at: int}>
     */
    private static function pool(string $action): array
    {
        $stored = $_SESSION[self::SESSION_KEY][$action] ?? [];

        if (!is_array($stored)) {
            return [];
        }

        $now = time();
        $pool = [];

        foreach ($stored as $entry) {
            if (
                is_array($entry)
                && is_string($entry['token'] ?? null)
                && is_int($entry['issued_at'] ?? null)
                && ($now - $entry['issued_at']) <= self::TOKEN_LIFETIME_SECONDS
            ) {
                $pool[] = ['token' => $entry['token'], 'issued_at' => $entry['issued_at']];
            }
        }

        return $pool;
    }

    private static function ensureSession(bool $startIfNeeded): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE && $startIfNeeded) {
            self::$sessions?->start();
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            if ($startIfNeeded) {
                throw new RuntimeException('Session must be started before using CSRF protection.');
            }

            return false;
        }

        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }

        return true;
    }
}
