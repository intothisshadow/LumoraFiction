<?php

/**
 * Turns a small piece of HTML (a summary or a note) into plain text with paragraph breaks.
 *
 * @package LumoraFiction
 * @subpackage Import
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Import;

/**
 * Story summaries and notes are plain text here, shown with their line breaks, so the markup of the source is dropped
 * and only its paragraph and line structure is kept.
 */
final class PlainText
{
    public static function fromHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $text = (string) preg_replace('~<\s*br\s*/?>~i', "\n", $html);
        $text = (string) preg_replace('~</\s*(?:p|div|li|blockquote|h[1-6]|ul|ol)\s*>~i', "\n\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = (string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text);
        $text = (string) preg_replace('/[ \t]+\n/', "\n", $text);
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
