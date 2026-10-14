<?php

/**
 * Turns text into a short, ASCII-only, URL-safe slug.
 *
 * @package LumoraFiction
 * @subpackage Helpers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Helpers;

/**
 * Slugs are ASCII only. Allowing arbitrary Unicode in URLs makes look-alike names possible
 * (a Cyrillic "a" in place of a Latin one), which is a real impersonation risk for author pages.
 */
final class Slug
{
    /** Names that would collide with, or be mistaken for, routes the application owns. */
    private const RESERVED = [
        'admin', 'administrator', 'api', 'account', 'author', 'authors', 'feed', 'feeds', 'login', 'logout',
        'register', 'password', 'search', 'story', 'stories', 'tag', 'tags', 'fandom', 'fandoms', 'install',
        'content', 'assets', 'index', 'moderator', 'editor', 'staff', 'support', 'help', 'about', 'new',
    ];

    private const TRANSLITERATION = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ď' => 'd', 'đ' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ě' => 'e', 'ę' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'ı' => 'i',
        'ł' => 'l', 'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ő' => 'o',
        'ŕ' => 'r', 'ř' => 'r', 'ś' => 's', 'š' => 's', 'ş' => 's', 'ß' => 'ss', 'ť' => 't', 'ţ' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z', 'æ' => 'ae', 'œ' => 'oe', 'þ' => 'th', 'ð' => 'd',
    ];

    /**
     * @param string $fallback used when the text has no Latin letters or digits; it must not be one of the reserved names
     */
    public static function fromText(string $text, int $maxLength = 60, string $fallback = 'writer'): string
    {
        $slug = strtr(mb_strtolower($text, 'UTF-8'), self::TRANSLITERATION);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim(substr(trim($slug, '-'), 0, $maxLength), '-');

        return $slug === '' ? $fallback : $slug;
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::RESERVED, true);
    }
}
