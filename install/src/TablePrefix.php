<?php

/**
 * Generates and checks the database table prefix chosen at install time.
 *
 * @package LumoraFiction
 * @subpackage Installer
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Installer;

use LumoraFiction\Core\Database\Database;

/**
 * A random prefix keeps table names unpredictable and stops a shared database from clashing with
 * another application. The fixed marker differs from Lumora Press's so both can share a database.
 */
final class TablePrefix
{
    public const MARKER = 'lumfic_';

    /** Prefixes common enough that choosing one defeats the point of having a prefix. */
    private const GUESSABLE = ['wp_', 'lp_', 'lf_', 'lum_', 'lumfic_', 'lumora_', 'fiction_', 'ao3_', 'ffn_', 'story_', 'stories_'];

    public static function generate(): string
    {
        return self::MARKER . bin2hex(random_bytes(3)) . '_';
    }

    /**
     * @return string|null a plain-language problem, or null when the prefix is acceptable
     */
    public static function problem(string $prefix): ?string
    {
        if (!Database::isValidPrefix($prefix)) {
            return 'Table prefix must start with a letter, use only letters, numbers, and underscores, and be 2 to 30 characters long.';
        }

        if (in_array(strtolower($prefix), self::GUESSABLE, true)) {
            return 'That table prefix is too easy to guess. Use the suggested random one, or add some random characters.';
        }

        return null;
    }
}
