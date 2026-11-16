<?php

/**
 * Lists the folders next to this site that look like an eFiction install.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\EfictionImporter;

final class FolderFinder
{
    /** What an eFiction 3.x install always has at its top level. */
    private const MARKERS = ['viewstory.php', 'config.php'];

    /**
     * Looks one level down in the folder beside this site and, when the host allows reading it, in the folder above that. A link
     * is never followed, every path is resolved first, and a folder the host's open_basedir forbids is left out without a word.
     *
     * @return list<string> full paths
     */
    public static function find(string $siteRoot): array
    {
        $own = realpath($siteRoot);

        if ($own === false) {
            return [];
        }

        $found = [];

        foreach ([dirname($own), dirname($own, 2)] as $parent) {
            foreach (self::inside($parent, $own) as $path) {
                $found[$path] = $path;
            }
        }

        return array_values($found);
    }

    /**
     * @return list<string>
     */
    private static function inside(string $parent, string $own): array
    {
        if (!self::readable($parent) || !is_dir($parent)) {
            return [];
        }

        $names = scandir($parent);

        if ($names === false) {
            return [];
        }

        $found = [];

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $parent . DIRECTORY_SEPARATOR . $name;

            if (is_link($path) || !self::readable($path) || !is_dir($path)) {
                continue;
            }

            $real = realpath($path);

            // A path that resolves somewhere else (a link further up, say) is not "next to" this site.
            if ($real === false || dirname($real) !== realpath($parent) || $real === $own) {
                continue;
            }

            if (self::looksLikeEfiction($real)) {
                $found[] = $real;
            }
        }

        sort($found);

        return $found;
    }

    public static function looksLikeEfiction(string $folder): bool
    {
        foreach (self::MARKERS as $marker) {
            $file = $folder . DIRECTORY_SEPARATOR . $marker;

            if (!self::readable($file) || !is_file($file)) {
                return false;
            }
        }

        return true;
    }

    /**
     * open_basedir makes PHP warn about a path outside its list, and a warning is not wanted here, so the list is checked first.
     */
    public static function readable(string $path): bool
    {
        $limit = (string) ini_get('open_basedir');

        if ($limit === '') {
            return true;
        }

        foreach (explode(PATH_SEPARATOR, $limit) as $allowed) {
            $allowed = rtrim($allowed, '/\\');

            if ($allowed !== '' && ($path === $allowed || str_starts_with($path, $allowed . DIRECTORY_SEPARATOR))) {
                return true;
            }
        }

        return false;
    }
}
