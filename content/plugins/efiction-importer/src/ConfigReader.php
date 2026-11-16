<?php

/**
 * Reads the database settings out of an eFiction folder's config.php as text, without ever running the file.
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

final class ConfigReader
{
    private const BYTES_MAX = 65536;

    /**
     * Only a plain quoted assignment counts, so an include, a function call or a string with a variable in it is left alone.
     * Each value is kept exactly as written, so the administrator can see and change it.
     *
     * @return array{host?: string, port?: int, name?: string, user?: string, password?: string, prefix?: string}
     */
    public static function read(string $folder): array
    {
        $file = rtrim($folder, '/\\') . '/config.php';

        if (!is_file($file) || is_link($file) || !is_readable($file)) {
            return [];
        }

        $text = file_get_contents($file, false, null, 0, self::BYTES_MAX);

        return $text === false ? [] : self::parse($text);
    }

    /**
     * @return array{host?: string, port?: int, name?: string, user?: string, password?: string, prefix?: string}
     */
    public static function parse(string $text): array
    {
        $found = [];

        foreach (['dbhost', 'dbname', 'dbuser', 'dbpass', 'settingsprefix'] as $variable) {
            $pattern = '/^[ \t]*\$' . $variable . '[ \t]*=[ \t]*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\$]|\\\\.)*)")[ \t]*;/m';

            if (preg_match($pattern, $text, $match) !== 1) {
                continue;
            }

            // PCRE leaves the second group out entirely when the single-quoted branch matched.
            $found[$variable] = isset($match[2])
                ? stripcslashes($match[2])
                : strtr($match[1], ['\\\\' => '\\', "\\'" => "'"]);
        }

        $result = [];

        if (isset($found['dbhost'])) {
            // "host:3307" is how eFiction's config carries a port.
            if (preg_match('/^([^:]+):(\d{1,5})\z/', $found['dbhost'], $parts) === 1) {
                $result['host'] = $parts[1];
                $result['port'] = (int) $parts[2];
            } else {
                $result['host'] = $found['dbhost'];
            }
        }

        foreach (['dbname' => 'name', 'dbuser' => 'user', 'dbpass' => 'password'] as $variable => $field) {
            if (isset($found[$variable])) {
                $result[$field] = $found[$variable];
            }
        }

        // The settings table is named {settingsprefix}fanfiction_settings, and the other tables normally share that prefix.
        if (isset($found['settingsprefix'])) {
            $result['prefix'] = $found['settingsprefix'] . 'fanfiction_';
        }

        return $result;
    }
}
