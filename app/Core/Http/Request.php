<?php

/**
 * What a handler needs to know about the request it is answering.
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

use LumoraFiction\Core\Security\SecurityHeaders;

/**
 * An immutable snapshot, so a handler never reads the PHP superglobals itself. That keeps handlers
 * testable and puts the rules for trusting client-supplied values (like the address) in one place.
 */
final readonly class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $server
     * @param array<string, mixed> $files
     * @param bool $uploadsTrusted only a test sets this; a real request is checked with is_uploaded_file()
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $query = [],
        public array $post = [],
        public array $cookies = [],
        public array $server = [],
        public array $files = [],
        public bool $uploadsTrusted = false,
    ) {
    }

    public static function fromGlobals(string $method, string $uri, ?TrustedProxies $proxies = null): self
    {
        return new self(strtoupper($method), $uri, $_GET, $_POST, $_COOKIE, $proxies === null ? $_SERVER : $proxies->resolve($_SERVER), $_FILES);
    }

    /**
     * A file sent with the form, or null when the field is absent, is not a single file, or names something PHP did
     * not itself receive in this request. The last check matters: a forged entry pointing at a server file must
     * never be read as if the visitor had uploaded it.
     */
    public function upload(string $name): ?UploadedFile
    {
        $entry = $this->files[$name] ?? null;

        if (!is_array($entry) || !is_string($entry['tmp_name'] ?? null) || !is_int($entry['error'] ?? null)) {
            return null;
        }

        $path = $entry['tmp_name'];

        if ($entry['error'] === UPLOAD_ERR_OK && !$this->uploadsTrusted && !is_uploaded_file($path)) {
            return null;
        }

        return new UploadedFile($path, $entry['error'], is_int($entry['size'] ?? null) ? $entry['size'] : 0);
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * A submitted form value, or null when it is missing or is not plain text (an array, for example).
     */
    public function input(string $name): ?string
    {
        $value = $this->post[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * The text values of a field submitted as a list (name[]), such as a set of ticked boxes. Anything that is
     * not plain text is dropped, so a hostile nested array cannot reach the code that reads the list.
     *
     * @return list<string>
     */
    public function inputList(string $name): array
    {
        $value = $this->post[$name] ?? null;

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    public function queryValue(string $name): ?string
    {
        $value = $this->query[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Whether a checkbox was ticked. Browsers send nothing at all for an unticked box.
     */
    public function checked(string $name): bool
    {
        return $this->input($name) === '1';
    }

    /**
     * The visitor's address. Forwarding headers are never read here: anyone can forge them. A site behind a reverse
     * proxy lists it under Settings > Security, and TrustedProxies has by then replaced REMOTE_ADDR with the address the
     * proxy vouched for, so this stays the one place that answers.
     */
    public function ip(): string
    {
        $address = $this->server['REMOTE_ADDR'] ?? null;

        return is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false ? $address : '0.0.0.0';
    }

    /**
     * The address the connection really came from, which differs from ip() only behind a trusted proxy.
     */
    public function peer(): string
    {
        $address = $this->server[TrustedProxies::PEER_KEY] ?? $this->server['REMOTE_ADDR'] ?? null;

        return is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false ? $address : '0.0.0.0';
    }

    public function isHttps(): bool
    {
        return SecurityHeaders::isHttps($this->server);
    }
}
