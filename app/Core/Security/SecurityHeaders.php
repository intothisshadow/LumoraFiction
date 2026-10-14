<?php

/**
 * Builds and sends the standard protective response headers.
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

/**
 * Everything here is sent unconditionally except HSTS, because nothing legitimate is lost by the
 * rest. HSTS is the one real trade-off: once a browser has seen it, it refuses plain HTTP for the
 * site for a year, so a site whose HTTPS later breaks becomes unreachable. It is opt-in, and only
 * ever sent over HTTPS.
 */
final class SecurityHeaders
{
    public const HSTS_MAX_AGE_SECONDS = 31536000;

    /**
     * @return array<string, string> header name => value
     */
    public static function headers(bool $isHttps, bool $hstsEnabled): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];

        if ($isHttps && $hstsEnabled) {
            $headers['Strict-Transport-Security'] = 'max-age=' . self::HSTS_MAX_AGE_SECONDS;
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     */
    public static function send(array $headers): void
    {
        if (headers_sent()) {
            return;
        }

        foreach ($headers as $name => $value) {
            if ($value !== '') {
                header($name . ': ' . $value);
            }
        }
    }

    /**
     * @param array<string, mixed> $server
     */
    public static function isHttps(array $server): bool
    {
        $https = $server['HTTPS'] ?? '';

        return (is_string($https) && $https !== '' && strtolower($https) !== 'off')
            || (string) ($server['SERVER_PORT'] ?? '') === '443';
    }
}
