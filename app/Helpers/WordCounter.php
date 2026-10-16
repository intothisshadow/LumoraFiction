<?php

/**
 * Counts the words in stored chapter text with the markup taken out.
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

use LumoraFiction\Models\ContentFormat;

/**
 * A word is a run of letters and digits, which may contain apostrophes or hyphens between them, so
 * "don't" and "well-known" each count once. A run of Chinese or Japanese text with no spaces counts as one
 * word, which is a known limit of counting without a dictionary.
 */
final class WordCounter
{
    public static function count(string $content, ContentFormat $format): int
    {
        // Invalid UTF-8 would make the Unicode pattern fail outright; replacing it keeps the count usable.
        $text = mb_scrub(self::plainText($content, $format), 'UTF-8');

        return (int) preg_match_all('/[\p{L}\p{N}\p{M}]+(?:[\'\x{2019}\-][\p{L}\p{N}\p{M}]+)*/u', $text);
    }

    private static function plainText(string $content, ContentFormat $format): string
    {
        return match ($format) {
            ContentFormat::Plain => $content,
            ContentFormat::Html => self::stripMarkup($content),
            ContentFormat::Markdown => self::stripMarkdown($content),
        };
    }

    private static function stripMarkup(string $html): string
    {
        // Script and style bodies are not reading text, and a block boundary must separate words
        // ("one</p><p>two" is two words, not "onetwo").
        $html = (string) preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', ' ', $html);
        $html = (string) preg_replace('~<(?:br|/p|/div|/li|/h[1-6]|/blockquote|/tr|hr)\b[^>]*>~i', ' ', $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function stripMarkdown(string $markdown): string
    {
        $text = self::stripMarkup($markdown);
        $text = (string) preg_replace('/^(?:```|~~~).*$/m', ' ', $text);
        $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        $text = (string) preg_replace('/^\s{0,3}(?:#{1,6}\s+|>\s?|[-*+]\s+|\d+[.)]\s+|[-*_]{3,}\s*$)/m', ' ', $text);

        return (string) preg_replace('/[*_~`]+/', '', $text);
    }
}
