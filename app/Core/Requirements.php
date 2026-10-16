<?php

/**
 * What a server needs in order to run Lumora Fiction, shared by the installer and System Information.
 *
 * @package LumoraFiction
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core;

/**
 * One list, so the installer and the System Information screen can never disagree about what is required.
 */
final class Requirements
{
    public const MINIMUM_PHP_VERSION = '8.2.0';

    /** @var list<string> */
    public const REQUIRED_EXTENSIONS = ['pdo', 'pdo_mysql', 'session', 'json', 'mbstring', 'zip'];

    /** @var list<string> */
    public const RECOMMENDED_EXTENSIONS = ['fileinfo', 'gd', 'intl', 'openssl', 'curl'];

    public const MINIMUM_MYSQL = '5.6.4';

    public const MINIMUM_MARIADB = '10.0.5';

    /**
     * @param string $reported the server's own version string, for example "10.11.6-MariaDB" or "8.0.36"
     * @return string|null a plain-language problem, or null when the version is acceptable or cannot be read
     */
    public static function databaseVersionProblem(string $reported): ?string
    {
        $isMariaDb = stripos($reported, 'mariadb') !== false;

        // Older PDO versions report MariaDB as "5.5.5-10.x.y-MariaDB" for replication compatibility.
        $clean = (string) preg_replace('/^5\.5\.5-/', '', $reported);

        if (preg_match('/^(\d+\.\d+\.\d+)/', $clean, $match) !== 1) {
            // An unrecognised string is not a reason to block something that would work.
            return null;
        }

        $product = $isMariaDb ? 'MariaDB' : 'MySQL';
        $minimum = $isMariaDb ? self::MINIMUM_MARIADB : self::MINIMUM_MYSQL;

        if (version_compare($match[1], $minimum, '<')) {
            return sprintf('This database server is too old (%s %s). %s %s or newer is required.', $product, $match[1], $product, $minimum);
        }

        return null;
    }
}
