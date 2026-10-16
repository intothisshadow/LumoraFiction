<?php

/**
 * Output escaping for HTML pages.
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

final class Html
{
    /**
     * Escapes text for HTML content and for quoted attribute values. Invalid UTF-8 is replaced with
     * the replacement character rather than dropped, so it cannot be used to splice two halves of a payload together.
     */
    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
