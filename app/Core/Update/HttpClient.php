<?php

/**
 * HTTPS requests for the updater, through cURL when the server has it and PHP streams when it does not.
 *
 * @package LumoraFiction
 * @subpackage Update
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Update;

use CurlHandle;

/**
 * Only https addresses are ever requested, certificates are always verified, and the size of an answer is capped as it
 * arrives, so a wrong or hostile server cannot fill the disk. The caller decides which hosts are acceptable.
 */
final class HttpClient implements TokenAwareTransport, HttpPoster
{
    private const CONNECT_TIMEOUT = 10;

    private const TIMEOUT = 120;

    private const MAX_REDIRECTS = 5;

    private const TOKEN_HOST = 'api.github.com';

    public function __construct(private readonly string $userAgent, private readonly string $token = '')
    {
    }

    public function withToken(string $token): static
    {
        return new static($this->userAgent, trim($token));
    }

    /**
     * The extra header for one request: only a request to GitHub's API carries the token, never a download or a redirect target.
     */
    private function authorization(string $url): ?string
    {
        if ($this->token === '' || strtolower((string) parse_url($url, PHP_URL_HOST)) !== self::TOKEN_HOST) {
            return null;
        }

        return 'Authorization: Bearer ' . $this->token;
    }

    public function fetch(string $url, int $maxBytes): string
    {
        $this->assertHttps($url);
        $body = '';

        $this->transfer($url, $maxBytes, static function (string $chunk) use (&$body): void {
            $body .= $chunk;
        });

        return $body;
    }

    /**
     * No redirect is followed and no token is sent, so the data only ever goes to the address that was asked for.
     */
    public function postJson(string $url, string $json, int $timeout): int
    {
        $this->assertHttps($url);

        if (function_exists('curl_init')) {
            $handle = curl_init($url);

            if (!$handle instanceof CurlHandle) {
                throw new UpdateException('This server could not start an outgoing connection.');
            }

            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                // The answer is not wanted; discarding it keeps a large one from being held in memory.
                CURLOPT_WRITEFUNCTION => static fn (CurlHandle $curl, string $chunk): int => strlen($chunk),
            ]);

            if (curl_exec($handle) === false) {
                throw new UpdateException('The connection failed: ' . $this->plain(curl_error($handle)));
            }

            return (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        }

        if (ini_get('allow_url_fopen') !== '1' && strtolower((string) ini_get('allow_url_fopen')) !== 'on') {
            throw new UpdateException('This server cannot make outgoing connections (it has neither cURL nor allow_url_fopen).');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => $timeout,
                'follow_location' => 0,
                'ignore_errors' => true,
                'header' => "User-Agent: " . $this->userAgent . "\r\nContent-Type: application/json\r\n",
                'content' => $json,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $stream = fopen($url, 'rb', false, $context);

        if ($stream === false) {
            throw new UpdateException('The connection failed.');
        }

        $status = 0;

        foreach (stream_get_meta_data($stream)['wrapper_data'] ?? [] as $header) {
            if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        fclose($stream);

        return $status;
    }

    public function download(string $url, string $destination, int $maxBytes): int
    {
        $this->assertHttps($url);
        $output = fopen($destination, 'wb');

        if ($output === false) {
            throw new UpdateException('The download cannot be saved. Check that the storage folder is writable and has room.');
        }

        $size = 0;

        try {
            $this->transfer($url, $maxBytes, static function (string $chunk) use ($output, &$size): void {
                if (fwrite($output, $chunk) === false) {
                    throw new UpdateException('The download cannot be saved. The disk may be full.');
                }

                $size += strlen($chunk);
            });
        } catch (UpdateException $exception) {
            fclose($output);
            unlink($destination);

            throw $exception;
        }

        fclose($output);

        return $size;
    }

    /**
     * @param callable(string): void $sink
     */
    private function transfer(string $url, int $maxBytes, callable $sink): void
    {
        $received = 0;
        $limited = static function (string $chunk) use (&$received, $maxBytes, $sink): void {
            $received += strlen($chunk);

            if ($received > $maxBytes) {
                throw new UpdateException('The answer is larger than expected, so it was not downloaded.');
            }

            $sink($chunk);
        };

        if (function_exists('curl_init')) {
            $this->withCurl($url, $limited);

            return;
        }

        $this->withStreams($url, $limited);
    }

    /**
     * @param callable(string): void $sink
     */
    private function withCurl(string $url, callable $sink): void
    {
        $handle = curl_init($url);

        if (!$handle instanceof CurlHandle) {
            throw new UpdateException('This server could not start an outgoing connection.');
        }

        $failure = null;

        // A redirect may only lead to another https address, never down to plain http.
        $redirectProtocols = defined('CURLOPT_REDIR_PROTOCOLS_STR') ? [CURLOPT_REDIR_PROTOCOLS_STR => 'https'] : [CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS];

        curl_setopt_array($handle, $redirectProtocols + [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => array_values(array_filter(['Accept: application/vnd.github+json, application/octet-stream, text/plain', $this->authorization($url)])),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $curl, string $chunk) use ($sink, &$failure): int {
                try {
                    $sink($chunk);
                } catch (UpdateException $exception) {
                    $failure = $exception;

                    return -1;
                }

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($failure instanceof UpdateException) {
            throw $failure;
        }

        if ($ok === false) {
            throw new UpdateException('The connection failed: ' . $this->plain(curl_error($handle)));
        }

        $this->assertStatus($status);
    }

    /**
     * @param callable(string): void $sink
     */
    private function withStreams(string $url, callable $sink): void
    {
        if (ini_get('allow_url_fopen') !== '1' && strtolower((string) ini_get('allow_url_fopen')) !== 'on') {
            throw new UpdateException('This server cannot make outgoing connections (it has neither cURL nor allow_url_fopen), so upload the update package by hand instead.');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT,
                // PHP resends its headers to wherever a redirect leads, so a request that carries the token follows none.
                'follow_location' => $this->authorization($url) === null ? 1 : 0,
                'max_redirects' => self::MAX_REDIRECTS,
                'ignore_errors' => true,
                'header' => "User-Agent: " . $this->userAgent . "\r\nAccept: application/vnd.github+json, application/octet-stream, text/plain\r\n" . ($this->authorization($url) ?? '') . ($this->authorization($url) === null ? '' : "\r\n"),
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $stream = fopen($url, 'rb', false, $context);

        if ($stream === false) {
            throw new UpdateException('The connection failed.');
        }

        $status = 0;

        foreach (stream_get_meta_data($stream)['wrapper_data'] ?? [] as $header) {
            if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        try {
            $this->assertStatus($status);

            while (!feof($stream)) {
                $chunk = fread($stream, 65536);

                if ($chunk === false) {
                    break;
                }

                $sink($chunk);
            }
        } finally {
            fclose($stream);
        }
    }

    private function assertHttps(string $url): void
    {
        if (!str_starts_with($url, 'https://')) {
            throw new UpdateException('Only secure (https) addresses are used for updates.');
        }
    }

    private function assertStatus(int $status): void
    {
        if ($status === 403 || $status === 429) {
            throw new UpdateException('GitHub is limiting requests from this server right now. Try again in a little while.');
        }

        if ($status === 404) {
            throw new UpdateException('GitHub has no such release or file. Check the repository name, or upload the package by hand.');
        }

        if ($status < 200 || $status >= 300) {
            throw new UpdateException('GitHub answered with an error (' . $status . '). Try again later.');
        }
    }

    private function plain(string $text): string
    {
        return mb_substr((string) preg_replace('/[^\p{L}\p{N}.,:;()\/ _-]+/u', '', $text), 0, 120);
    }
}
