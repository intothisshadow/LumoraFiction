<?php

/**
 * Reads the visitor's address and HTTPS from a reverse proxy's headers, but only when the proxy is one the site trusts.
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

use LumoraFiction\Core\Security\IpRangeMatcher;

/**
 * Anyone can send an X-Forwarded-For header, so it is only believed when the connection itself comes from a proxy the
 * administrator listed. Even then the header is read from the right: each proxy adds the address it saw to the end, so the
 * last entry that is not one of our own proxies is the first one a trusted proxy vouches for, and anything to its left was
 * written by the visitor and is never used. With no trusted proxies, which is the default, nothing here changes anything.
 */
final class TrustedProxies
{
    /** The server key that keeps the address the connection really came from. */
    public const PEER_KEY = 'LF_PEER_ADDR';

    /** @var list<string> */
    private readonly array $rules;

    /**
     * @param list<string> $rules addresses or ranges of the proxies
     */
    public function __construct(array $rules = [])
    {
        $this->rules = array_values(array_filter($rules, IpRangeMatcher::isValidRule(...)));
    }

    public static function fromSetting(mixed $setting): self
    {
        return new self(is_string($setting) ? IpRangeMatcher::parseList($setting) : []);
    }

    public function any(): bool
    {
        return $this->rules !== [];
    }

    /**
     * The server variables with REMOTE_ADDR and HTTPS replaced by what a trusted proxy reported. The real peer is kept
     * under PEER_KEY. Returned unchanged when the connection is not from a trusted proxy.
     *
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    public function resolve(array $server): array
    {
        $peer = $server['REMOTE_ADDR'] ?? null;

        if (!is_string($peer) || !$this->trusts($peer)) {
            return $server;
        }

        $server[self::PEER_KEY] = $peer;
        $server['REMOTE_ADDR'] = $this->clientAddress($peer, $server['HTTP_X_FORWARDED_FOR'] ?? null);

        $proto = $server['HTTP_X_FORWARDED_PROTO'] ?? null;

        if (is_string($proto) && strtolower(trim(explode(',', $proto)[0])) === 'https') {
            $server['HTTPS'] = 'on';
        }

        return $server;
    }

    public function trusts(string $address): bool
    {
        return $this->rules !== [] && IpRangeMatcher::matches($address, $this->rules);
    }

    private function clientAddress(string $peer, mixed $forwardedFor): string
    {
        if (!is_string($forwardedFor) || trim($forwardedFor) === '') {
            return $peer;
        }

        $entries = array_map('trim', explode(',', $forwardedFor));
        $leftmost = $peer;

        for ($i = count($entries) - 1; $i >= 0; $i--) {
            // A value that is not an address cannot be vouched for, so nothing from the header is used.
            if (filter_var($entries[$i], FILTER_VALIDATE_IP) === false) {
                return $peer;
            }

            if (!$this->trusts($entries[$i])) {
                return $entries[$i];
            }

            $leftmost = $entries[$i];
        }

        // Every hop was one of our own proxies, so the leftmost is as far back as the chain goes.
        return $leftmost;
    }
}
