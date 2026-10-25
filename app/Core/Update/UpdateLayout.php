<?php

/**
 * Which files of an installation an update, a backup and a restore may touch.
 *
 * @package LumoraFiction
 * @subpackage Update
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Update;

/**
 * One list of what belongs to the application, so an update can never delete or overwrite what belongs to the site:
 * the configuration, uploads, runtime storage, and any plugin or theme the release does not ship. A path that is not
 * named here is left alone by every operation, however a package is laid out.
 */
final class UpdateLayout
{
    /** Folders owned by the application: replaced wholesale, files the release dropped are removed. */
    public const REPLACED_DIRECTORIES = ['app', 'admin', 'assets', 'docs'];

    /** Folders whose children are packages of their own. Each shipped package folder is replaced; others are never touched. */
    public const PACKAGE_PARENTS = ['content/plugins', 'content/themes'];

    /** Folders in which only the loose files the release ships (such as the web server rules) are updated. */
    private const LOOSE_FILE_DIRECTORIES = ['content/plugins', 'content/themes', 'content/uploads'];

    private const LOOSE_FILES = ['.htaccess', 'index.html'];

    /** Files shipped inside folders that are otherwise the site's own. */
    private const SINGLE_FILES = ['config/config.example.php', 'config/.htaccess', 'storage/.htaccess'];

    private const PACKAGE_SLUG = '/^[a-z0-9][a-z0-9_-]*\z/';

    private const ROOT_FILE = '/^[A-Za-z0-9][A-Za-z0-9._-]*\z|^\.htaccess\z/';

    /**
     * Whether a path (relative to the site root, with forward slashes) is something an update may write.
     */
    public static function isUpdatable(string $relative): bool
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..') || str_contains($relative, '\\')) {
            return false;
        }

        $segments = explode('/', $relative);

        if (count($segments) === 1) {
            return preg_match(self::ROOT_FILE, $relative) === 1;
        }

        if (in_array($segments[0], self::REPLACED_DIRECTORIES, true)) {
            return true;
        }

        if (in_array($relative, self::SINGLE_FILES, true)) {
            return true;
        }

        if (self::packageOf($relative) !== null) {
            return true;
        }

        if (count($segments) === 2 && $segments[0] === 'content') {
            return self::isLooseFile($segments[1]);
        }

        return count($segments) === 3 && in_array($segments[0] . '/' . $segments[1], self::LOOSE_FILE_DIRECTORIES, true) && self::isLooseFile($segments[2]);
    }

    /**
     * The package folder ("content/plugins/slug") a path belongs to, or null when it is not inside one.
     */
    public static function packageOf(string $relative): ?string
    {
        $segments = explode('/', $relative);

        if (count($segments) < 4 || $segments[0] !== 'content' || !in_array($segments[1], ['plugins', 'themes'], true)) {
            return null;
        }

        return preg_match(self::PACKAGE_SLUG, $segments[2]) === 1 ? $segments[0] . '/' . $segments[1] . '/' . $segments[2] : null;
    }

    /**
     * The folder that is mirrored for a path: an application folder or a package folder. Null for a single file.
     */
    public static function mirroredRootOf(string $relative): ?string
    {
        $segments = explode('/', $relative);

        if (count($segments) > 1 && in_array($segments[0], self::REPLACED_DIRECTORIES, true)) {
            return $segments[0];
        }

        return self::packageOf($relative);
    }

    /**
     * Only the files that tell the web server how to treat a folder. Anything else sitting loose in these folders,
     * above all in uploads, belongs to the site.
     */
    private static function isLooseFile(string $name): bool
    {
        return in_array($name, self::LOOSE_FILES, true);
    }
}
