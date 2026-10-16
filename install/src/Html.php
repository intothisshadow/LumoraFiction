<?php

/**
 * Output escaping for the installer's pages.
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

final class Html
{
    /**
     * Escapes text for use in HTML content and in quoted attribute values.
     */
    public static function e(string $text): string
    {
        return \LumoraFiction\Helpers\Html::e($text);
    }
}
