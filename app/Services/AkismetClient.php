<?php

/**
 * Optional spam check of new sign-ups with Akismet.
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

use Closure;
use Throwable;

/**
 * Off unless an administrator turns it on, and the site works the same without it. It fails open: an answer that is
 * not a plain "true" or "false", or no answer at all, is reported as unknown and never blocks anyone. Only what Akismet
 * needs to judge a sign-up is sent: the address, the display name, the visitor's address and browser, and the site's own
 * address.
 */
final class AkismetClient
{
    private const HOST = 'rest.akismet.com';

    private const USER_AGENT = 'LumoraFiction/1.0 | Akismet/1.0';

    private const TIMEOUT_SECONDS = 8;

    /**
     * @param (Closure(string $url, array<string, string> $fields, int $timeoutSeconds): (string|null))|null $post
     *     Replaced in tests so nothing leaves the machine; production passes nothing.
     */
    public function __construct(
        private readonly string $siteUrl,
        private readonly ?Closure $post = null,
    ) {
    }

    /**
     * @return bool|null true when Akismet accepts the key, false when it refuses it, null when it could not be asked
     */
    public function verifyKey(string $key): ?bool
    {
        if ($key === '') {
            return false;
        }

        $body = $this->send('https://' . self::HOST . '/1.1/verify-key', ['key' => $key, 'blog' => $this->siteUrl]);

        return match ($body === null ? null : trim($body)) {
            'valid' => true,
            'invalid' => false,
            default => null,
        };
    }

    /**
     * @return bool|null true when Akismet calls the sign-up spam, false when it does not, null when it could not be asked
     */
    public function isSpamSignup(string $key, string $displayName, string $email, string $ipAddress, string $userAgent): ?bool
    {
        $fields = array_filter([
            'blog' => $this->siteUrl,
            'user_ip' => $ipAddress,
            'user_agent' => mb_substr($userAgent, 0, 400),
            'comment_type' => 'signup',
            'comment_author' => $displayName,
            'comment_author_email' => $email,
        ], static fn (string $value): bool => $value !== '');

        $body = $this->send('https://' . $key . '.' . self::HOST . '/1.1/comment-check', $fields);

        return match ($body === null ? null : trim($body)) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }

    /**
     * @param array<string, string> $fields
     */
    private function send(string $url, array $fields): ?string
    {
        try {
            return ($this->post ?? $this->postOverHttps(...))($url, $fields, self::TIMEOUT_SECONDS);
        } catch (Throwable $exception) {
            // The error handler turns the warning a refused connection raises into an exception; fail open.
            error_log('[AkismetClient] ' . $exception::class . ': ' . $exception->getMessage());

            return null;
        }
    }

    /**
     * @param array<string, string> $fields
     */
    private function postOverHttps(string $url, array $fields, int $timeoutSeconds): ?string
    {
        $payload = http_build_query($fields);
        $headers = ['User-Agent: ' . self::USER_AGENT, 'Content-Type: application/x-www-form-urlencoded'];

        if (function_exists('curl_init')) {
            $handle = curl_init($url);

            if ($handle === false) {
                return null;
            }

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $body = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);

            return is_string($body) && $status >= 200 && $status < 300 ? $body : null;
        }

        if (ini_get('allow_url_fopen') === '0' || ini_get('allow_url_fopen') === '') {
            return null;
        }

        $context = stream_context_create([
            'http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $payload, 'timeout' => $timeoutSeconds, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $body = file_get_contents($url, false, $context);

        return is_string($body) ? $body : null;
    }
}
