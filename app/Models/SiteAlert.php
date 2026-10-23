<?php

/**
 * One alert about the site itself, either checked live or kept from an event.
 *
 * @package LumoraFiction
 * @subpackage Models
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Models;

use DateTimeImmutable;

final class SiteAlert
{
    public const MESSAGE_MAX = 500;

    public const DETAIL_MAX = 1000;

    /**
     * @param string $path an admin address relative to the admin root, such as "maintenance/plugins"; never a full address
     * @param int|null $id the stored row, or null for an alert worked out live, which can only clear by its cause going
     */
    public function __construct(
        public readonly string $key,
        public readonly AlertSeverity $severity,
        public readonly string $message,
        public readonly string $detail = '',
        public readonly string $path = '',
        public readonly int $occurrences = 1,
        public readonly ?DateTimeImmutable $firstSeen = null,
        public readonly ?DateTimeImmutable $lastSeen = null,
        public readonly ?int $id = null,
    ) {
    }

    public static function validKey(string $key): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]{0,99}\z/', $key) === 1;
    }

    public static function validPath(string $path): bool
    {
        return preg_match('#^([a-z0-9][a-z0-9/_-]{0,99})?\z#', $path) === 1 && !str_contains($path, '//');
    }

    /**
     * Reads what a plugin supplied. Anything malformed gives null, so one careless alert is dropped and never breaks the
     * screen or the dashboard.
     */
    public static function fromArray(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }

        $key = $value['key'] ?? null;
        $severity = $value['severity'] ?? null;
        $message = $value['message'] ?? null;
        $detail = $value['detail'] ?? '';
        $path = $value['path'] ?? '';

        if (!is_string($key) || !self::validKey($key)) {
            return null;
        }

        $severity = $severity instanceof AlertSeverity ? $severity : (is_string($severity) ? AlertSeverity::tryFrom($severity) : null);

        if ($severity === null || !is_string($message) || trim($message) === '' || mb_strlen($message) > self::MESSAGE_MAX) {
            return null;
        }

        if (!is_string($detail) || mb_strlen($detail) > self::DETAIL_MAX || !is_string($path) || !self::validPath($path)) {
            return null;
        }

        return new self($key, $severity, trim($message), trim($detail), $path);
    }
}
