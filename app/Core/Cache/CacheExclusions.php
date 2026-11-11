<?php

/**
 * The never-cache path list: one pattern per line, matched against the address path.
 *
 * @package LumoraFiction
 * @subpackage Cache
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Cache;

/**
 * A pattern is matched against the path only, never the query string. `*` matches any run of characters, slashes
 * included, and everything else is literal, so a pasted address cannot be misread as a regular expression. Blank lines
 * and lines starting with `#` are ignored.
 */
final class CacheExclusions
{
    public const MAX_LINES = 100;

    public const MAX_LENGTH = 200;

    /**
     * @return list<string> the patterns, each starting with a single slash
     */
    public static function parse(string $patterns): array
    {
        $parsed = [];

        foreach (preg_split('/\R/', $patterns) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parsed[] = '/' . ltrim($line, '/');
        }

        return $parsed;
    }

    /**
     * The first line that is not a usable pattern, or null when every one is.
     */
    public static function problem(string $patterns): ?string
    {
        $found = self::parse($patterns);

        if (count($found) > self::MAX_LINES) {
            return 'Use at most ' . self::MAX_LINES . ' lines.';
        }

        foreach ($found as $pattern) {
            if (strlen($pattern) > self::MAX_LENGTH || preg_match('#^/[A-Za-z0-9/_.*~%+-]*\z#', $pattern) !== 1) {
                return 'The line "' . mb_substr($pattern, 0, 40, 'UTF-8') . '" is not a path. Use letters, digits and / _ . - ~ % + and * only.';
            }
        }

        return null;
    }

    public static function matches(string $path, string $patterns): bool
    {
        $path = self::normalise($path);

        foreach (self::parse($patterns) as $pattern) {
            if (preg_match('#^' . str_replace('\*', '.*', preg_quote(self::normalise($pattern), '#')) . '\z#', $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /** `/about` and `/about/` are the same page to the router. */
    private static function normalise(string $path): string
    {
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
