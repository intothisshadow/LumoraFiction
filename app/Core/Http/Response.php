<?php

/**
 * What a handler sends back: a status, headers, and a body.
 *
 * @package LumoraFiction
 * @subpackage Http
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Http;

use InvalidArgumentException;

/**
 * Returned by handlers rather than printed, so a handler can be tested by looking at the value it
 * returns. Header names and values are checked when the response is built: a line break in either
 * would let a value that came from a visitor add a header of its own.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = [],
        public readonly ?string $file = null,
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('Invalid HTTP status.');
        }

        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]+\z/', (string) $name) !== 1 || preg_match('/[\r\n\x00]/', $value) === 1) {
                throw new InvalidArgumentException('Invalid response header.');
            }
        }
    }

    /**
     * @param array<string, string> $headers
     */
    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8'] + $headers);
    }

    /**
     * Only an address on this same site is accepted. A path that starts with "//" or "/\" is read by
     * browsers as another site, so it is refused too; that is what stops an open redirect.
     */
    public static function redirect(string $path, int $status = 302): self
    {
        if (preg_match('#^/(?![/\\\\])[^\r\n\x00\\\\]*\z#', $path) !== 1) {
            throw new InvalidArgumentException('A redirect must go to a path on this site.');
        }

        return new self('', $status, ['Location' => $path]);
    }

    public static function plain(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * A file the browser saves rather than shows. The name is reduced to safe characters.
     */
    public static function download(string $body, string $filename, string $contentType): self
    {
        $safe = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename), '.-') ?: 'download';

        return new self($body, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
        ]);
    }

    /**
     * A file on disk the browser saves, sent in pieces so a large one never has to fit in memory. The caller has already
     * decided the path is one that may be sent.
     */
    public static function file(string $path, string $filename, string $contentType): self
    {
        $safe = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename), '.-') ?: 'download';

        return new self('', 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Content-Length' => (string) filesize($path),
            'Cache-Control' => 'private, no-store',
        ], $path);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->body, $this->status, [$name => $value] + $this->headers, $this->file);
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $this->body;

        if ($this->file !== null) {
            readfile($this->file);
        }
    }
}
