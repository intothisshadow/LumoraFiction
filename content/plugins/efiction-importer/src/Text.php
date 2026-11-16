<?php

/**
 * Text handling for the importer: repairing character sets and turning eFiction's HTML, plain text or a mixture into HTML.
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

final class Text
{
    /** The characters a Windows-1252 text read as UTF-8 turns into: "Ã©", "â€™", "Â ". */
    private const MOJIBAKE = '/Ã[\x{0080}-\x{00BF}]|â€[\x{0090}-\x{00BF}\x{2018}-\x{201D}\x{2026}\x{2122}]|Â[\x{00A0}-\x{00BF}]/u';

    /**
     * Makes sure the text is UTF-8. Bytes that are not valid UTF-8 are read in the character set the site declared (or
     * Windows-1252), and text that is valid UTF-8 but was clearly UTF-8 read as Windows-1252 and saved again is turned back.
     *
     * @return array{0: string, 1: bool} the text and whether it needed repairing
     */
    public static function utf8(string $text, string $declared = 'Windows-1252'): array
    {
        $text = str_replace("\0", '', $text);

        if (!mb_check_encoding($text, 'UTF-8')) {
            return [mb_convert_encoding($text, 'UTF-8', self::charset($declared)), true];
        }

        if (preg_match(self::MOJIBAKE, $text) === 1) {
            $back = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

            // Characters that have no Windows-1252 form come back as "?", so a result with more of them was not a clean round trip.
            if (mb_check_encoding($back, 'UTF-8') && substr_count($back, '?') <= substr_count($text, '?')) {
                return [$back, true];
            }
        }

        return [$text, false];
    }

    /**
     * The character set to read old text in: what the site's language file says when that is one PHP knows, else Windows-1252,
     * which is also what a site that says ISO-8859-1 almost always means.
     */
    public static function charset(string $declared): string
    {
        return match (strtolower(trim($declared))) {
            'iso-8859-2' => 'ISO-8859-2',
            'iso-8859-15' => 'ISO-8859-15',
            'windows-1250' => 'Windows-1250',
            'windows-1251' => 'Windows-1251',
            'koi8-r' => 'KOI8-R',
            default => 'Windows-1252',
        };
    }

    /**
     * eFiction keeps chapter text as HTML (from its editor), as plain text (typed in a box, shown with its line breaks kept) or
     * as a mixture, with tags for emphasis and bare line breaks between paragraphs. Text with a block-level tag is taken as
     * HTML. Anything else gets its blank lines turned into paragraphs and its single line breaks into <br>.
     */
    public static function toHtml(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if ($text === '') {
            return '';
        }

        if (preg_match('~<\s*(?:p|div|blockquote|ul|ol|h[1-6]|table|pre)\b~i', $text) === 1) {
            return $text;
        }

        // Text with no tags at all is escaped whole; text with inline tags keeps them and has only the stray & and < tidied.
        if (preg_match('~<\s*/?\s*[a-z][^>]*>~i', $text) !== 1) {
            $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $paragraphs = preg_split('/\n[ \t]*\n+/', $text) ?: [$text];
        $html = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph !== '') {
                $html .= '<p>' . preg_replace('/[ \t]*\n[ \t]*/', "<br>\n", $paragraph) . "</p>\n";
            }
        }

        return trim($html);
    }

    /**
     * Notes, summaries and comments are plain text here, so tags are dropped and the paragraph breaks kept.
     */
    public static function plain(string $html): string
    {
        $text = (string) preg_replace('~<\s*br\s*/?>~i', "\n", $html);
        $text = (string) preg_replace('~</\s*(?:p|div|li|blockquote|h[1-6]|ul|ol)\s*>~i', "\n\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = (string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text);
        $text = (string) preg_replace('/[ \t]+\n/', "\n", $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    public static function cut(string $text, int $max): string
    {
        return mb_strlen($text, 'UTF-8') <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1, 'UTF-8')) . '…';
    }

    /**
     * A source date, or null when the database holds eFiction's "zero date" or nothing usable.
     */
    public static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000')) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
