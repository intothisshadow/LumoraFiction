<?php

/**
 * The directory the site is installed in, for sites that do not sit at the domain root.
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

/**
 * Empty for a domain-root install, "/lumorafiction" for a subdirectory install. Static because
 * URL helpers used by themes and plugins have no other way to reach it.
 */
final class BasePath
{
    private static string $value = '';

    public static function set(string $basePath): void
    {
        self::$value = rtrim($basePath, '/');
    }

    public static function get(): string
    {
        return self::$value;
    }

    /**
     * Derives the base path from SCRIPT_NAME, which is the front controller's own URL path.
     *
     * @param array<string, mixed> $server
     */
    public static function detect(array $server): string
    {
        $script = $server['SCRIPT_NAME'] ?? '';

        if (!is_string($script) || $script === '') {
            return '';
        }

        $directory = str_replace('\\', '/', dirname($script));

        if ($directory === '/' || $directory === '.') {
            return '';
        }

        // The value is later placed into URLs, so anything outside a plain path is discarded.
        return preg_match('#^/[A-Za-z0-9._~/-]*$#', $directory) === 1 ? rtrim($directory, '/') : '';
    }

    /**
     * Removes the base path from a request URI so route patterns never contain it.
     */
    public static function stripFrom(string $uri): string
    {
        if (self::$value === '') {
            return $uri;
        }

        // Match whole path segments only, so "/lumorafiction-other" is not treated as being
        // inside "/lumorafiction".
        if ($uri !== self::$value && !str_starts_with($uri, self::$value . '/') && !str_starts_with($uri, self::$value . '?')) {
            return $uri;
        }

        $stripped = substr($uri, strlen(self::$value));

        return $stripped === '' || $stripped[0] === '?' ? '/' . $stripped : $stripped;
    }
}
