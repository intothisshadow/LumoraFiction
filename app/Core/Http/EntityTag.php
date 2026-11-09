<?php

/**
 * The validator for conditional responses: an ETag made from the body, and the check against a request's If-None-Match.
 *
 * @package LumoraFiction
 * @subpackage Http
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Http;

/**
 * Only the ETag is used, never a Last-Modified date. A date taken from the newest entry cannot tell that an older entry
 * was removed, so a client holding the old copy would be told nothing had changed. A hash of the body always can.
 */
final class EntityTag
{
    public static function of(string $body): string
    {
        return '"' . hash('sha256', $body) . '"';
    }

    /**
     * Whether the client already holds this version. If-Modified-Since is deliberately ignored.
     */
    public static function matches(Request $request, string $etag): bool
    {
        $sent = $request->server['HTTP_IF_NONE_MATCH'] ?? null;

        if (!is_string($sent) || trim($sent) === '') {
            return false;
        }

        if (trim($sent) === '*') {
            return true;
        }

        foreach (explode(',', $sent) as $candidate) {
            // A weak validator is enough for a GET, so the W/ prefix is not part of the comparison.
            if (self::strip($candidate) === self::strip($etag)) {
                return true;
            }
        }

        return false;
    }

    private static function strip(string $tag): string
    {
        $tag = trim($tag);

        return str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;
    }
}
