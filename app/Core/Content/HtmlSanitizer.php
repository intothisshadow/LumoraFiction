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

        $this->cleanNode($root);

        return $this->innerHtml($root, $dom);
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
