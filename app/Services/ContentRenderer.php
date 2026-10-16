<?php

/**
 * The one place a chapter's stored text becomes safe, final HTML.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Content\HtmlSanitizer;
use LumoraFiction\Core\Content\HtmlToMarkdownConverter;
use LumoraFiction\Core\Content\MarkdownParser;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Models\ContentFormat;

/**
 * This is the only code that branches on ContentFormat, and every branch ends in HtmlSanitizer, so there
 * is exactly one XSS boundary for all four editing modes:
 *
 *   Markdown -> parsed to HTML -> sanitised
 *   Html     -> sanitised (hand-typed or visual editor output; both are untrusted client input)
 *   Plain    -> escaped, then split into paragraphs and line breaks; never read as markup
 */
final class ContentRenderer
{
    public function __construct(
        private readonly MarkdownParser $markdown,
        private readonly HtmlSanitizer $sanitizer,
        private readonly HookManager $hooks,
        private readonly HtmlToMarkdownConverter $htmlToMarkdown = new HtmlToMarkdownConverter(),
    ) {
    }

    /**
     * Plugins may adjust the result with the "content_html" filter, but the sanitiser runs again over
     * whatever they return, so a plugin cannot reopen the boundary by mistake.
     */
    public function render(string $content, ContentFormat $format): string
    {
        $html = match ($format) {
            ContentFormat::Markdown => $this->sanitizer->clean($this->markdown->toHtml($content)),
            ContentFormat::Html => $this->sanitizer->clean($content),
            ContentFormat::Plain => $this->plainToHtml($content),
        };

        $filtered = $this->hooks->applyFilters('content_html', $html, $format);

        return $this->sanitizer->repairNesting($this->sanitizer->clean(is_string($filtered) ? $filtered : $html));
    }

    /**
     * Changes how a chapter is written without changing what it says. Converting to or from plain text
     * returns the text unchanged, because plain text has no structure to carry across and switching to
     * it by mistake should not lose anything.
     */
    public function convertFormat(string $content, ContentFormat $from, ContentFormat $to): string
    {
        if ($from === $to || $from === ContentFormat::Plain || $to === ContentFormat::Plain) {
            return $content;
        }

        if ($to === ContentFormat::Html) {
            return $this->sanitizer->clean($this->markdown->toHtml($content));
        }

        return $this->htmlToMarkdown->toMarkdown($this->sanitizer->clean($content));
    }

    /**
     * Plain text for excerpts and search snippets, taken from the rendered result so that no Markdown
     * syntax leaks into it.
     */
    public function toPlainText(string $content, ContentFormat $format): string
    {
        if ($format === ContentFormat::Plain) {
            return trim((string) preg_replace('/\s+/', ' ', $content));
        }

        $html = $this->render($content, $format);
        // A space after every closing tag stops "<p>A</p><p>B</p>" fusing into "AB".
        $spaced = (string) preg_replace(['#</[a-z][a-z0-9]*>#i', '#<br\s*/?>#i'], ['$0 ', ' '], $html);
        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return trim((string) preg_replace('/\s+([.,!?;:)])/', '$1', (string) preg_replace('/\s+/', ' ', $text)));
    }

    /**
     * Blank lines separate paragraphs and single line breaks stay as breaks.
     */
    private function plainToHtml(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if ($text === '') {
            return '';
        }

        $paragraphs = preg_split('/\n{2,}/', $text) ?: [];

        return implode("\n", array_map(
            static fn (string $paragraph): string => '<p>' . nl2br(htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>',
            $paragraphs,
        ));
    }
}
