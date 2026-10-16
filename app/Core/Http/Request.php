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
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $query = [],
        public array $post = [],
        public array $cookies = [],
        public array $server = [],
    ) {
    }

    public static function fromGlobals(string $method, string $uri): self
    {
        return new self(strtoupper($method), $uri, $_GET, $_POST, $_COOKIE, $_SERVER);
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
     * The visitor's address as the web server saw it. Forwarding headers are deliberately ignored:
     * anyone can forge them, so trusting them is a separate, opt-in setting for sites behind a proxy.
     */
    public function ip(): string
    {
        $address = $this->server['REMOTE_ADDR'] ?? null;

        return is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false ? $address : '0.0.0.0';
    }

    public function isHttps(): bool
    {
        return SecurityHeaders::isHttps($this->server);
    }
}
