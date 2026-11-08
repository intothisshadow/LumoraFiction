<?php

/**
 * Turns the Markdown text of a GitHub release into HTML that is safe to print on the Updates screen.
 *
 * @package LumoraFiction
 * @subpackage Update
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.3.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Update;

use LumoraFiction\Core\Content\HtmlSanitizer;
use LumoraFiction\Core\Content\MarkdownParser;
use Throwable;

/**
 * A release body comes from outside the site, so it gets the same treatment as a chapter a stranger typed: the Markdown
 * parser never passes raw HTML through, and the sanitiser then allows only text formatting, headings, lists and
 * http, https or mailto links. It allows no images at all, so a badge in the body can never make the browser request
 * anything. Every link opens in a new tab without telling the target page where it came from.
 */
final class ReleaseNotes
{
    public function __construct(
        private readonly MarkdownParser $markdown = new MarkdownParser(),
        private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
    ) {
    }

    /**
     * @return string|null safe HTML, or null when the text cannot be read, so the caller can print it as plain text
     */
    public function toHtml(string $notes): ?string
    {
        if (trim($notes) === '') {
            return '';
        }

        try {
            $html = $this->sanitizer->clean($this->markdown->toHtml($this->withoutImages($notes)));
        } catch (Throwable) {
            return null;
        }

        // The sanitiser keeps a link's rel only when it opens a new tab, so both are set here, after cleaning.
        return (string) preg_replace('/<a\s+(?![^>]*\b(?:rel|target)=)/i', '<a target="_blank" rel="noopener noreferrer" ', $html);
    }

    /**
     * An image is written like a link with a "!" in front. Dropping the mark leaves a link, which the reader may choose
     * to open, instead of a picture the browser would fetch by itself.
     */
    private function withoutImages(string $notes): string
    {
        return (string) preg_replace('/!(\[[^\]]*\]\()/', '$1', $notes);
    }
}
