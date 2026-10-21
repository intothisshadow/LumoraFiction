<?php

/**
 * Whether an internet address is in a list of addresses and ranges.
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
 * Works on the packed form from inet_pton(), so IPv4 and IPv6 share one comparison and an IPv4 address can never match an
 * IPv6 rule, or the reverse, by accident. A malformed rule matches nothing: a typo in a list that grants trust must never
 * open anything up.
 */
final class IpRangeMatcher
{
    /**
     * @param list<string> $rules single addresses ("203.0.113.5", "2001:db8::1") or ranges ("203.0.113.0/24")
     */
    public static function matches(string $ipAddress, array $rules): bool
    {
        $ip = inet_pton($ipAddress);

        if ($ip === false) {
            return false;
        }

        foreach ($rules as $rule) {
            $parsed = self::parse($rule);

            if ($parsed !== null && self::contains($parsed['address'], $parsed['prefix'], $ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads a box of rules: one per line, commas also separate, and `#` starts a comment.
     *
     * @return list<string>
     */
    public static function parseList(string $raw): array
    {
        $rules = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            foreach (preg_split('/[\s,]+/', trim(explode('#', $line, 2)[0]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $rule) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    public static function isValidRule(string $rule): bool
    {
        return self::parse($rule) !== null;
    }

    /**
     * The number of leading bits a rule fixes, or null when it is not a rule. A single address fixes all of them.
     */
    public static function prefixLength(string $rule): ?int
    {
        return self::parse($rule)['prefix'] ?? null;
    }

    public static function isIpv4Rule(string $rule): bool
    {
        $parsed = self::parse($rule);

        return $parsed !== null && strlen($parsed['address']) === 4;
    }

    /**
     * @return array{address: string, prefix: int}|null
     */
    private static function parse(string $rule): ?array
    {
        [$address, $prefix] = array_pad(explode('/', trim($rule), 2), 2, null);
        $packed = inet_pton($address);

        if ($packed === false) {
            return null;
        }

        $bits = strlen($packed) * 8;

        if ($prefix === null) {
            return ['address' => $packed, 'prefix' => $bits];
        }

        if (preg_match('/^[0-9]{1,3}\z/', $prefix) !== 1 || (int) $prefix > $bits) {
            return null;
        }

        return ['address' => $packed, 'prefix' => (int) $prefix];
    }

    private static function contains(string $network, int $prefix, string $ip): bool
    {
        if (strlen($network) !== strlen($ip)) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);

        if (substr($network, 0, $wholeBytes) !== substr($ip, 0, $wholeBytes)) {
            return false;
        }

        $remaining = $prefix % 8;

        if ($remaining === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($network[$wholeBytes]) & $mask) === (ord($ip[$wholeBytes]) & $mask);
    }
}
