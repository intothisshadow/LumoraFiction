<?php

/**
 * Allowlist HTML sanitiser: the single XSS boundary for everything the content renderer outputs.
 *
 * @package LumoraFiction
 * @subpackage Content
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Content;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Markdown output, hand-typed HTML and WYSIWYG markup are all untrusted by the time they reach here.
 * Built on DOMDocument rather than regex stripping, which has a long history of bypasses on malformed
 * or nested tags. The allowlist is tuned for fiction: text formatting, headings, lists, quotations,
 * rules and links. There are no images, tables, forms, embeds or inline styles.
 */
final class HtmlSanitizer
{
    /** @var array<string, list<string>> tag => allowed attributes */
    private const ALLOWED_TAGS = [
        // 'class' carries the editor's text-alignment classes, since a style attribute is never allowed.
        'p' => ['class'],
        'br' => [],
        'hr' => [],
        'h1' => ['class'], 'h2' => ['class'], 'h3' => ['class'], 'h4' => ['class'], 'h5' => ['class'], 'h6' => ['class'],
        'strong' => [], 'b' => [],
        'em' => [], 'i' => [],
        'u' => [],
        'del' => [], 's' => [],
        'sup' => [], 'sub' => [],
        'code' => ['class'],
        'pre' => ['class'],
        'blockquote' => [],
        'ul' => [], 'ol' => [], 'li' => [],
        'a' => ['href', 'title', 'rel', 'target'],
        'span' => ['class'],
    ];

    private const ALLOWED_URL_SCHEMES = ['http', 'https', 'mailto'];

    /**
     * Class values worth keeping: the editor's own alignment and scene-break styling and a code block's
     * language. Anything else is dropped so stored content cannot borrow a theme's or the admin's classes.
     */
    private const CLASS_PATTERN = '/^(?:lf-[a-z0-9-]{1,40}|language-[a-z0-9+#-]{1,20})\z/';

    /**
     * Tags a real browser would implicitly close a paragraph for rather than nest inside it. libxml2's
     * HTML parser does not do this, so repairNesting() fixes the resulting malformed nesting explicitly.
     *
     * @var list<string>
     */
    private const BLOCK_LEVEL_TAGS = [
        'p', 'div', 'ul', 'ol', 'li', 'blockquote', 'pre', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    ];

    /**
     * @param array<string, list<string>>|null $allowedTags override the default allowlist (tests only)
     */
    public function __construct(
        private readonly ?array $allowedTags = null,
    ) {
    }

    public function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // A stray closing div would close the wrapper below and send everything after it outside the
        // part that is read back, silently dropping the author's text. Divs are not allowed anyway.
        $html = (string) preg_replace('~</\s*div\b[^>]*>~i', '', $html);

        // Word's downlevel markers ("<![if !supportLists]>") are read as text by libxml2 and would print on
        // the page. The comment form ("<!--[if IE]>...<![endif]-->") is left alone: it is dropped as a
        // comment, and cutting its closing marker would turn the rest of the text into one.
        $html = (string) preg_replace(['~<!\[if\s[^\]]*\]>~i', '~<!\[endif\]>(?!-->)~i'], '', $html);

        // libxml2 drops a namespace prefix, so Word's "<o:p>" would parse as a second, nested paragraph.
        $html = (string) preg_replace('~</?o:p\b[^>]*>~i', '', $html);

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        // Wrapped in a root element and a forced encoding so multibyte text is not mis-decoded and no
        // html/body wrapper has to be stripped differently across libxml versions.
        $dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="lf-sanitize-root">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();

        $root = $dom->getElementById('lf-sanitize-root');

        if ($root === null) {
            return '';
        }

        // Styles are read here and then discarded by cleanNode(), so the formatting survives as real tags.
        $this->translatePastedFormatting($root);
        $this->cleanNode($root);
        $this->removeEmptyWrappers($root);

        return $this->innerHtml($root, $dom);
    }

    /**
     * Google Docs and Word mark italics, bold and underline with a style on a span, which would be dropped
     * with every other style. The marks become em, strong, u and friends first; the span is left behind
     * empty of meaning and is unwrapped by cleanNode().
     */
    private function translatePastedFormatting(DOMElement $root): void
    {
        $this->unwrapNeutralFormatting($root);
        $this->convertWordLists($root);
        $this->flattenTables($root);

        foreach (iterator_to_array($root->getElementsByTagName('span')) as $span) {
            if ($span instanceof DOMElement && $span->hasAttribute('style')) {
                $this->wrapStyledChildren($span);
            }
        }
    }

    /**
     * Google Docs wraps a whole paste in a bold tag whose style says the weight is normal, and states
     * "not italic" on italic tags the same way. Read literally these would bold the entire chapter.
     */
    private function unwrapNeutralFormatting(DOMElement $root): void
    {
        foreach (iterator_to_array($root->getElementsByTagName('*')) as $element) {
            if (!$element instanceof DOMElement || $element->parentNode === null) {
                continue;
            }

            $tag = strtolower($element->tagName);
            $style = $this->parseStyle($element->getAttribute('style'));
            $neutral = match ($tag) {
                'b', 'strong' => str_starts_with(strtolower($element->getAttribute('id')), 'docs-internal-guid')
                    || (isset($style['font-weight']) && !$this->isBold($style['font-weight'])),
                'i', 'em' => ($style['font-style'] ?? '') === 'normal',
                default => false,
            };

            if ($neutral) {
                $this->unwrap($element);
            }
        }
    }

    private function wrapStyledChildren(DOMElement $span): void
    {
        $style = $this->parseStyle($span->getAttribute('style'));
        $tags = [];

        if (in_array($style['font-style'] ?? '', ['italic', 'oblique'], true)) {
            $tags[] = 'em';
        }

        if (isset($style['font-weight']) && $this->isBold($style['font-weight'])) {
            $tags[] = 'strong';
        }

        // Google Docs underlines every link's text; a second underline inside a link is noise.
        if (preg_match('/\bunderline\b/', ($style['text-decoration'] ?? '') . ' ' . ($style['text-decoration-line'] ?? '')) === 1 && !$this->insideLink($span)) {
            $tags[] = 'u';
        }

        if (preg_match('/\bline-through\b/', ($style['text-decoration'] ?? '') . ' ' . ($style['text-decoration-line'] ?? '')) === 1) {
            $tags[] = 's';
        }

        $align = $style['vertical-align'] ?? '';

        if ($align === 'super') {
            $tags[] = 'sup';
        } elseif ($align === 'sub') {
            $tags[] = 'sub';
        }

        $dom = $span->ownerDocument;

        if ($tags === [] || $dom === null) {
            return;
        }

        $outer = null;
        $inner = null;

        foreach ($tags as $tag) {
            $wrapper = $dom->createElement($tag);

            if ($inner === null) {
                $outer = $wrapper;
            } else {
                $inner->appendChild($wrapper);
            }

            $inner = $wrapper;
        }

        while ($span->firstChild !== null && $inner !== null) {
            $inner->appendChild($span->firstChild);
        }

        if ($outer !== null) {
            $span->appendChild($outer);
        }
    }

    private function insideLink(DOMNode $node): bool
    {
        for ($parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (strtolower($parent->tagName) === 'a') {
                return true;
            }
        }

        return false;
    }

    private function isBold(string $weight): bool
    {
        return in_array($weight, ['bold', 'bolder'], true) || (ctype_digit($weight) && (int) $weight >= 600);
    }

    /**
     * @return array<string, string> lower-cased property => value, with "!important" removed
     */
    private function parseStyle(string $style): array
    {
        $parsed = [];

        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $value = trim((string) preg_replace('/\s*!important\s*$/i', '', strtolower(trim($parts[1]))));
            $parsed[strtolower(trim($parts[0]))] = $value;
        }

        return $parsed;
    }

    /**
     * Word writes a list item as a styled paragraph with the bullet or number typed in a marked-off span.
     * Consecutive item paragraphs become one real list; nesting levels are flattened.
     */
    private function convertWordLists(DOMElement $root): void
    {
        $dom = $root->ownerDocument;

        if ($dom === null) {
            return;
        }

        $list = null;
        $listKind = '';
        $previous = null;

        foreach (iterator_to_array($root->getElementsByTagName('p')) as $paragraph) {
            if (!$paragraph instanceof DOMElement || $paragraph->parentNode === null || !$this->isWordListItem($paragraph)) {
                continue;
            }

            $kind = 'ul';

            foreach (iterator_to_array($paragraph->getElementsByTagName('span')) as $marker) {
                if ($marker instanceof DOMElement && preg_match('/mso-list\s*:\s*ignore/i', $marker->getAttribute('style')) === 1) {
                    if (preg_match('/^[\s\x{00A0}]*(?:\d+|[a-z]|[ivxlcdm]+)[.)]/iu', $marker->textContent) === 1) {
                        $kind = 'ol';
                    }

                    $marker->parentNode?->removeChild($marker);
                }
            }

            // Only paragraphs that are neighbours join one list; any other block between them ends it.
            if ($list === null || $kind !== $listKind || $paragraph->previousSibling !== $previous) {
                $list = $dom->createElement($kind);
                $listKind = $kind;
                $paragraph->parentNode->insertBefore($list, $paragraph);
            }

            $item = $dom->createElement('li');

            while ($paragraph->firstChild !== null) {
                $item->appendChild($paragraph->firstChild);
            }

            $list->appendChild($item);
            $paragraph->parentNode->removeChild($paragraph);
            $previous = $list;
        }
    }

    private function isWordListItem(DOMElement $paragraph): bool
    {
        return preg_match('/mso-list\s*:\s*(?!none)\S/i', $paragraph->getAttribute('style')) === 1
            || str_starts_with(strtolower($paragraph->getAttribute('class')), 'msolistparagraph');
    }

    /**
     * Tables are not part of the allowlist, so a pasted one would run its cells together into one line.
     * Each cell becomes its own paragraph instead, in reading order, and the table frame is unwrapped.
     */
    private function flattenTables(DOMElement $root): void
    {
        $dom = $root->ownerDocument;

        if ($dom === null) {
            return;
        }

        foreach (iterator_to_array($root->getElementsByTagName('*')) as $cell) {
            if (!$cell instanceof DOMElement || $cell->parentNode === null || !in_array(strtolower($cell->tagName), ['td', 'th', 'caption'], true)) {
                continue;
            }

            if ($this->findBlockDescendant($cell) !== null) {
                continue;
            }

            $paragraph = $dom->createElement('p');

            while ($cell->firstChild !== null) {
                $paragraph->appendChild($cell->firstChild);
            }

            $cell->parentNode->replaceChild($paragraph, $cell);
        }
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    /**
     * A paste leaves hollow formatting tags behind ("<b></b>", a span around nothing) and a paragraph of
     * only line breaks where the source had a blank line. Children are visited before their parents, so
     * a wrapper emptied by removing its child goes too. A whitespace-only wrapper is unwrapped instead,
     * since the space between two words must survive.
     */
    private function removeEmptyWrappers(DOMElement $root): void
    {
        $wrappers = ['strong', 'b', 'em', 'i', 'u', 'del', 's', 'sup', 'sub', 'span', 'a'];

        foreach (array_reverse(iterator_to_array($root->getElementsByTagName('*'))) as $element) {
            if (!$element instanceof DOMElement || $element->parentNode === null) {
                continue;
            }

            $tag = strtolower($element->tagName);

            if (in_array($tag, $wrappers, true) && $element->getElementsByTagName('br')->length === 0) {
                $text = $element->textContent;

                if ($text === '') {
                    $element->parentNode->removeChild($element);
                } elseif (preg_match('/^\s*\z/u', $text) === 1) {
                    $this->unwrap($element);
                }

                continue;
            }

            if ($tag === 'p' && !$element->hasAttribute('class') && $this->holdsOnlyLineBreaks($element)) {
                $element->parentNode->removeChild($element);

                continue;
            }

            // A line break straight between two blocks (a pasted blank line) adds nothing.
            if ($tag === 'br' && $element->parentNode === $root && $this->touchesBlock($element)) {
                $root->removeChild($element);
            }
        }
    }

    private function touchesBlock(DOMElement $element): bool
    {
        foreach ([$element->previousSibling, $element->nextSibling] as $sibling) {
            if ($sibling instanceof DOMElement && in_array(strtolower($sibling->tagName), self::BLOCK_LEVEL_TAGS, true)) {
                return true;
            }
        }

        return false;
    }

    private function holdsOnlyLineBreaks(DOMElement $paragraph): bool
    {
        foreach ($paragraph->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'br') {
                continue;
            }

            if (!$child instanceof DOMText || trim($child->textContent) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Fixes invalid block-inside-paragraph nesting without touching tags or attributes.
     */
    public function repairNesting(string $html): string
    {
        if (!str_contains($html, '<p')) {
            return $html;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="lf-repair-root">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();

        $root = $dom->getElementById('lf-repair-root');

        if ($root === null) {
            return $html;
        }

        $this->unnestBlocksFromParagraphs($root);
        $this->removeEmptyParagraphs($root);

        return $this->innerHtml($root, $dom);
    }

    private function innerHtml(DOMElement $root, DOMDocument $dom): string
    {
        $output = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * Finds a paragraph containing an illegal block-level descendant and hoists it out, one violation per
     * pass, because hoisting changes the tree (a paragraph can end up split in two, each needing a re-check).
     */
    private function unnestBlocksFromParagraphs(DOMElement $root): void
    {
        do {
            $hoisted = false;

            foreach (iterator_to_array($root->getElementsByTagName('p')) as $p) {
                if (!$p instanceof DOMElement) {
                    continue;
                }

                $block = $this->findBlockDescendant($p);

                if ($block === null) {
                    continue;
                }

                $this->hoistOutOfParagraph($p, $block);
                $hoisted = true;

                break;
            }
        } while ($hoisted);
    }

    /**
     * libxml2 closes an open paragraph when a block starts but leaves the now-empty paragraph behind.
     */
    private function removeEmptyParagraphs(DOMElement $root): void
    {
        foreach (iterator_to_array($root->getElementsByTagName('p')) as $p) {
            if ($p instanceof DOMElement && !$p->hasChildNodes() && $p->parentNode !== null) {
                $p->parentNode->removeChild($p);
            }
        }
    }

    private function findBlockDescendant(DOMElement $element): ?DOMElement
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            if (in_array(strtolower($child->tagName), self::BLOCK_LEVEL_TAGS, true)) {
                return $child;
            }

            $nested = $this->findBlockDescendant($child);

            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    /**
     * Splits the paragraph, and every inline wrapper between it and the block, around the block so the
     * block ends up a sibling of the paragraph, as an HTML5 parser would do.
     */
    private function hoistOutOfParagraph(DOMElement $p, DOMElement $block): void
    {
        while ($block->parentNode !== $p) {
            $parent = $block->parentNode;

            if (!$parent instanceof DOMElement) {
                return;
            }

            $this->splitAroundChild($parent, $block);
        }

        $this->splitAroundChild($p, $block);
    }

    /**
     * Replaces $element with [before, child, after] copies, dropping an empty before or after rather
     * than leaving a stray empty tag.
     */
    private function splitAroundChild(DOMElement $element, DOMNode $child): void
    {
        $parent = $element->parentNode;
        $dom = $element->ownerDocument;

        if ($parent === null || $dom === null) {
            return;
        }

        $before = $dom->createElement($element->tagName);
        $after = $dom->createElement($element->tagName);

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $before->setAttribute($attribute->nodeName, $attribute->nodeValue ?? '');
            $after->setAttribute($attribute->nodeName, $attribute->nodeValue ?? '');
        }

        $sawChild = false;

        foreach (iterator_to_array($element->childNodes) as $node) {
            if ($node === $child) {
                $sawChild = true;

                continue;
            }

            ($sawChild ? $after : $before)->appendChild($node);
        }

        if ($before->hasChildNodes()) {
            $parent->insertBefore($before, $element);
        }

        $parent->insertBefore($child, $element);

        if ($after->hasChildNodes()) {
            $parent->insertBefore($after, $element);
        }

        $parent->removeChild($element);
    }

    private function cleanNode(DOMNode $node): void
    {
        $allowedTags = $this->allowedTags ?? self::ALLOWED_TAGS;

        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                continue;
            }

            if (!$child instanceof DOMElement) {
                $node->removeChild($child);

                continue;
            }

            $tag = strtolower($child->tagName);

            if (!array_key_exists($tag, $allowedTags)) {
                // Unwrap rather than delete, so the words inside a disallowed wrapper survive. Script,
                // style and similar are the exception: their text is the payload itself, so unwrapping
                // would still print it into the page.
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'textarea', 'select', 'svg', 'math', 'title', 'head'], true)) {
                    $node->removeChild($child);

                    continue;
                }

                $this->cleanNode($child);

                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }

                $node->removeChild($child);

                continue;
            }

            $this->cleanAttributes($child, $allowedTags[$tag]);
            $this->cleanNode($child);

            // A span only ever carried a class or a style; with neither left it is just a wrapper.
            if ($tag === 'span' && !$child->hasAttributes()) {
                $this->unwrap($child);
            }
        }
    }

    /**
     * @param list<string> $allowedAttributes
     */
    private function cleanAttributes(DOMElement $element, array $allowedAttributes): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = $attribute->nodeValue ?? '';

            if (str_starts_with($name, 'on') || !in_array($name, $allowedAttributes, true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if ($name === 'href' && !$this->isSafeUrl($value)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if ($name === 'class') {
                $classes = array_values(array_filter(
                    preg_split('/\s+/', trim($value)) ?: [],
                    static fn (string $class): bool => preg_match(self::CLASS_PATTERN, $class) === 1,
                ));

                if ($classes === []) {
                    $element->removeAttribute($attribute->nodeName);
                } else {
                    $element->setAttribute('class', implode(' ', $classes));
                }

                continue;
            }

            if ($name === 'target' && $value !== '_blank') {
                $element->removeAttribute($attribute->nodeName);
            }
        }

        // A link that opens a new tab always carries noopener, whether or not the author remembered it.
        if (strtolower($element->tagName) === 'a' && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * Control characters are removed before the scheme is read because browsers ignore them inside a
     * scheme, so "java\tscript:" would otherwise pass as a relative path.
     */
    private function isSafeUrl(string $url): bool
    {
        $url = trim((string) preg_replace('/[\x00-\x20\x7f]+/', '', $url));

        // Scheme-relative "//host/path" resolves to an arbitrary external host, so it is not a same-site path.
        if (str_starts_with($url, '//') || str_starts_with($url, '\\')) {
            return false;
        }

        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, '/') || str_starts_with($url, '.') || str_starts_with($url, '?')) {
            return true;
        }

        $colon = strpos($url, ':');

        if ($colon === false) {
            return true;
        }

        // A colon after a slash, question mark or hash belongs to a path or query, not a scheme.
        $before = substr($url, 0, $colon);

        if (strpbrk($before, '/?#') !== false) {
            return true;
        }

        return in_array(strtolower($before), self::ALLOWED_URL_SCHEMES, true);
    }
}
