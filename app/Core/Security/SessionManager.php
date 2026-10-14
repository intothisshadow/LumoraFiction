<?php

/**
 * Starts, regenerates, and destroys PHP sessions with secure cookie settings.
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

use SessionHandlerInterface;

/**
 * The cookie is HttpOnly, SameSite=Lax, Secure over HTTPS, and ends with the browser session.
 * The default name differs from Lumora Press's so both can run on one domain without overwriting
 * each other's sessions.
 */
final class SessionManager
{
    public const DEFAULT_NAME = 'lumora_fiction_session';

    public function __construct(
        private readonly bool $secureCookies,
        private readonly string $sessionName = self::DEFAULT_NAME,
        private readonly ?SessionHandlerInterface $handler = null,
        /** How long an idle session is kept before PHP's garbage collector may remove it; null keeps the host's own setting. */
        private readonly ?int $maxLifetimeSeconds = null,
        /** Directory for file-based sessions when no handler is given (the installer, before a database exists). */
        private readonly ?string $savePath = null,
    ) {
    }

    public function name(): string
    {
        return $this->sessionName;
    }

    /**
     * Starts the session only if the visitor already holds a session cookie, so anonymous readers
     * are never given a cookie and their pages stay cacheable.
     *
     * @param array<string, mixed> $cookies
     */
    public function startIfExisting(array $cookies): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        if (!isset($cookies[$this->sessionName]) || !is_string($cookies[$this->sessionName]) || $cookies[$this->sessionName] === '') {
            return false;
        }

        $this->start();

        return true;
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if ($this->handler !== null) {
            session_set_save_handler($this->handler, true);
        } elseif ($this->savePath !== null && is_dir($this->savePath) && is_writable($this->savePath)) {
            // Shared hosts often leave PHP's default session directory unwritable or shared with
            // other sites, so file sessions are kept in a directory this application owns.
            session_save_path($this->savePath);
        }

        // Debian and Ubuntu hosts ship PHP with session garbage collection switched off and rely on
        // a distribution cron job that only sweeps the default path. With sessions stored in the
        // database nothing would ever clean them up, so collection is forced back on.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');

        if ($this->maxLifetimeSeconds !== null) {
            ini_set('session.gc_maxlifetime', (string) $this->maxLifetimeSeconds);
        }

        // Session IDs are only ever accepted from the cookie, never from a URL parameter.
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');

        session_name($this->sessionName);

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $this->secureCookies,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    public function isActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function regenerate(bool $deleteOldSession = true): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id($deleteOldSession);
        }
    }

    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies') && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }
}
