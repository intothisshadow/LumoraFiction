<?php

/**
 * Reads any HTML file (a saved web page, a word processor export, a hand-written file) as a story: the title, the text and the chapters it plainly holds.
 *
 * @package LumoraFiction
 * @subpackage Import
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Import;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use LumoraFiction\Helpers\WordCounter;
use LumoraFiction\Models\ContentFormat;

/**
 * Nothing about a general file is trusted except its words: it has no byline, rating or classification that can be
 * relied on, so none is invented, and its own dates (a saved page carries the day it was saved) are ignored. Page
 * furniture is dropped by element, never by the name of a site, and what is kept is rebuilt from a short list of tags,
 * so styles, classes, fonts and colours from a word processor never arrive. The result is still passed through the
 * sanitiser when it is imported, like everything written in the editor.
 */
final class GeneralHtmlParser implements WorkParser
{
    /** How a file can be cut into chapters, with the words the screens use. */
    public const SPLITS = [
        'none' => 'One chapter per file',
        'h1' => 'A new chapter at each main heading',
        'h2' => 'A new chapter at each heading',
        'h3' => 'A new chapter at each smaller heading',
        'hr' => 'A new chapter at each horizontal line',
    ];

    /** Elements that are page furniture, not story. */
    private const FURNITURE = ['nav', 'header', 'footer', 'aside', 'form', 'dialog', 'menu'];

    /** Elements whose content is never text, or is something that runs or loads. */
    private const EMBEDDED = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'svg', 'canvas', 'template', 'applet', 'audio', 'video'];

    /** Dropped silently, since they hold no story and nothing worth counting. */
    private const IGNORED = ['head', 'title', 'meta', 'link', 'base', 'button', 'select', 'textarea', 'input', 'option', 'map', 'area', 'param', 'source', 'track'];

    private const INLINE = ['a', 'abbr', 'b', 'bdi', 'bdo', 'big', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'font', 'i', 'ins', 'kbd', 'label', 'mark', 'q', 's', 'samp', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'time', 'tt', 'u', 'var', 'br', 'wbr', 'o:p'];

    private string $split = 'none';

    /** @var array{embedded: int, furniture: int, images: int} */
    private array $tally = ['embedded' => 0, 'furniture' => 0, 'images' => 0];

    public function withSplit(string $mode): self
    {
        $copy = clone $this;
        $copy->split = isset(self::SPLITS[$mode]) ? $mode : 'none';

        return $copy;
    }

    public function parse(string $html, string $fileName = ''): OtwWork
    {
        $this->tally = ['embedded' => 0, 'furniture' => 0, 'images' => 0];
        $original = $html;

        // A text file saved with an .html name has no tags at all: its blank lines are its paragraph breaks.
        if (!str_contains($html, '<')) {
            $html = '<body>' . implode('', array_map(
                static fn (string $part): string => trim($part) === '' ? '' : '<p>' . nl2br(htmlspecialchars(trim($part), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>',
                preg_split('/\R{2,}/u', $html) ?: [],
            )) . '</body>';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $loaded ? $dom->getElementsByTagName('body')->item(0) : null;

        if (!$body instanceof DOMElement) {
            throw new ImportException('This file could not be read as an HTML page.');
        }

        $blocks = $this->blocks($body);
        $titleTag = $dom->getElementsByTagName('title')->item(0);
        $title = '';

        // With the file split at main headings, those are chapter titles, so the story's title comes from elsewhere.
        if ($this->split !== 'h1') {
            foreach ($blocks as $position => $block) {
                if ($block['tag'] === 'h1') {
                    $title = $block['text'];
                    array_splice($blocks, $position, 1);

                    break;
                }
            }
        }

        if ($title === '' && $titleTag !== null) {
            $title = $this->squash($titleTag->textContent);
        }

        if ($title === '') {
            $title = $this->titleFromFile($fileName);
        }

        $notes = [];
        $chapters = $this->chapters($blocks, $notes);

        if ($chapters === []) {
            throw new ImportException('This file has no text to import.');
        }

        $words = array_sum(array_map(static fn (OtwChapter $chapter): int => WordCounter::count($chapter->html, ContentFormat::Html), $chapters));

        if ($this->tally['embedded'] > 0) {
            $notes[] = $this->tally['embedded'] . ' script, style or embedded item' . ($this->tally['embedded'] === 1 ? ' was' : 's were') . ' removed from the text.';
        }

        if ($this->tally['furniture'] > 0) {
            $notes[] = 'Navigation, headers, footers, sidebars or forms of the page were left out.';
        }

        if ($this->tally['images'] > 0) {
            $notes[] = $this->tally['images'] . ' image' . ($this->tally['images'] === 1 ? ' was' : 's were') . ' left out, since pictures from other places are not imported.';
        }

        $language = $this->language($dom);

        return new OtwWork(
            $fileName,
            mb_substr($title, 0, 255, 'UTF-8'),
            '',
            '',
            '',
            '',
            '',
            [],
            [],
            [],
            [],
            [],
            [],
            $language,
            '',
            0,
            [],
            null,
            null,
            false,
            $words,
            '',
            '',
            $chapters,
            'general',
            hash('sha256', $original),
            $notes,
        );
    }

    /**
     * The story's block-level pieces in order, each as clean HTML with its tag and its plain text.
     *
     * @return list<array{tag: string, html: string, text: string}>
     */
    private function blocks(DOMNode $parent): array
    {
        $blocks = [];
        $buffer = '';

        $flush = function () use (&$blocks, &$buffer): void {
            foreach ($this->paragraphs($buffer) as $paragraph) {
                $blocks[] = ['tag' => 'p', 'html' => '<p>' . $paragraph . '</p>', 'text' => $this->squash(strip_tags($paragraph))];
            }

            $buffer = '';
        };

        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMComment) {
                continue;
            }

            if ($node instanceof DOMText) {
                $buffer .= htmlspecialchars($node->data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                continue;
            }

            if (!$node instanceof DOMElement) {
                continue;
            }

            $name = strtolower($node->nodeName);

            if ($this->dropped($node, $name)) {
                continue;
            }

            if (in_array($name, self::INLINE, true)) {
                $buffer .= $this->inline($node);

                continue;
            }

            $flush();

            switch (true) {
                case $name === 'hr':
                    $blocks[] = ['tag' => 'hr', 'html' => '<hr>', 'text' => ''];

                    break;
                case $name === 'img':
                    $this->tally['images']++;

                    break;
                case $name === 'p':
                    foreach ($this->paragraphs($this->inlineChildren($node)) as $paragraph) {
                        $blocks[] = ['tag' => 'p', 'html' => '<p>' . $paragraph . '</p>', 'text' => $this->squash(strip_tags($paragraph))];
                    }

                    break;
                case preg_match('/^h[1-6]\z/', $name) === 1:
                    $inner = trim($this->inlineChildren($node));
                    $text = $this->squash(strip_tags($inner));

                    if ($text !== '') {
                        $blocks[] = ['tag' => $name, 'html' => '<' . $name . '>' . $inner . '</' . $name . '>', 'text' => $text];
                    }

                    break;
                case $name === 'blockquote':
                    $inside = $this->blocks($node);

                    if ($inside !== []) {
                        $blocks[] = ['tag' => 'blockquote', 'html' => '<blockquote>' . implode('', array_column($inside, 'html')) . '</blockquote>', 'text' => $this->squash(implode(' ', array_column($inside, 'text')))];
                    }

                    break;
                case $name === 'ul' || $name === 'ol':
                    $list = $this->list($node, $name);

                    if ($list !== '') {
                        $blocks[] = ['tag' => $name, 'html' => $list, 'text' => $this->squash(strip_tags($list))];
                    }

                    break;
                case $name === 'pre':
                    $text = trim($node->textContent, "\r\n");

                    if (trim($text) !== '') {
                        $blocks[] = ['tag' => 'pre', 'html' => '<pre>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>', 'text' => $this->squash($text)];
                    }

                    break;
                default:
                    // A division, a table, a section or anything unknown only groups what is inside it.
                    array_push($blocks, ...$this->blocks($node));
            }
        }

        $flush();

        return $blocks;
    }

    /**
     * Whether an element is page furniture or embedded content, and so left out; the counts are kept for the preview.
     */
    private function dropped(DOMElement $node, string $name): bool
    {
        if (in_array($name, self::EMBEDDED, true)) {
            $this->tally['embedded']++;

            return true;
        }

        if (in_array($name, self::FURNITURE, true)) {
            $this->tally['furniture']++;

            return true;
        }

        return in_array($name, self::IGNORED, true);
    }

    /**
     * The inline HTML of one element's children.
     */
    private function inlineChildren(DOMNode $node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $html .= htmlspecialchars($child->data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } elseif ($child instanceof DOMElement) {
                $html .= $this->inline($child);
            }
        }

        return $html;
    }

    /**
     * A short list of inline tags is kept; every other tag is replaced by what is inside it, and no attribute survives
     * except the address of a link, which must be a web or mail address.
     */
    private function inline(DOMElement $node): string
    {
        $name = strtolower($node->nodeName);

        if ($this->dropped($node, $name)) {
            return '';
        }

        if ($name === 'img') {
            $this->tally['images']++;

            return '';
        }

        if ($name === 'br') {
            return '<br>';
        }

        if ($name === 'wbr') {
            return '';
        }

        $inner = $this->inlineChildren($node);

        // A block inside a line (a paragraph in a link, say) still starts a new line.
        if (in_array($name, ['p', 'div', 'li', 'tr', 'section', 'article'], true)) {
            return '<br>' . $inner . '<br>';
        }

        if (trim(strip_tags($inner)) === '' && !str_contains($inner, '<br>')) {
            return $inner;
        }

        $tag = match ($name) {
            'b', 'strong' => 'strong',
            'i', 'em', 'cite', 'dfn', 'var' => 'em',
            's', 'strike', 'del' => 's',
            'u', 'ins' => 'u',
            'sup' => 'sup',
            'sub' => 'sub',
            'code', 'kbd', 'samp', 'tt' => 'code',
            default => null,
        };

        if ($tag !== null) {
            return '<' . $tag . '>' . $inner . '</' . $tag . '>';
        }

        if ($name === 'a') {
            $href = trim($node->getAttribute('href'));

            return preg_match('~^(?:https?://|mailto:)[^\s"<>]+\z~i', $href) === 1
                ? '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $inner . '</a>'
                : $inner;
        }

        // Block elements inside a line were handled above; everything else (span, font, o:p) only holds text.
        return $inner;
    }

    /**
     * A list, with nested lists kept; items hold lines of text, not blocks.
     */
    private function list(DOMElement $node, string $name): string
    {
        $items = '';

        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement || strtolower($child->nodeName) !== 'li') {
                continue;
            }

            $text = '';
            $nested = '';

            foreach ($child->childNodes as $part) {
                if ($part instanceof DOMElement && in_array(strtolower($part->nodeName), ['ul', 'ol'], true)) {
                    $nested .= $this->list($part, strtolower($part->nodeName));
                } elseif ($part instanceof DOMText) {
                    $text .= htmlspecialchars($part->data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                } elseif ($part instanceof DOMElement) {
                    $text .= $this->inline($part);
                }
            }

            $text = trim((string) preg_replace('/(?:<br>\s*)+\z|\A(?:\s*<br>)+/', '', trim($text)));

            if (trim(strip_tags($text)) !== '' || $nested !== '') {
                $items .= '<li>' . $text . $nested . '</li>';
            }
        }

        return $items === '' ? '' : '<' . $name . '>' . $items . '</' . $name . '>';
    }

    /**
     * Paragraphs of inline HTML: two line breaks in a row, which is how plain pages and many exports separate paragraphs,
     * end one; a paragraph with no text (an empty line, a lone space) is dropped.
     *
     * @return list<string>
     */
    private function paragraphs(string $inline): array
    {
        $paragraphs = [];

        foreach (preg_split('~(?:<br>\s*){2,}~', $inline) ?: [] as $part) {
            $part = trim((string) preg_replace('/^(?:\s|<br>)+|(?:\s|<br>)+\z/u', '', str_replace("\u{00A0}", ' ', $part)));
            $part = (string) preg_replace('/[ \t\r\n]+/', ' ', $part);

            if (trim(strip_tags($part)) !== '') {
                $paragraphs[] = $part;
            }
        }

        return $paragraphs;
    }

    /**
     * @param list<array{tag: string, html: string, text: string}> $blocks
     * @param list<string> $notes
     * @return list<OtwChapter>
     */
    private function chapters(array $blocks, array &$notes): array
    {
        $groups = [];
        $current = ['title' => '', 'blocks' => []];
        $found = false;

        foreach ($blocks as $block) {
            $splits = $this->split === 'hr' ? $block['tag'] === 'hr' : ($this->split !== 'none' && $block['tag'] === $this->split);

            if ($splits) {
                $found = true;
                $groups[] = $current;
                $current = ['title' => $this->split === 'hr' ? '' : mb_substr($block['text'], 0, 255, 'UTF-8'), 'blocks' => []];

                continue;
            }

            $current['blocks'][] = $block;
        }

        $groups[] = $current;

        if ($this->split !== 'none' && !$found) {
            $notes[] = 'No ' . ($this->split === 'hr' ? 'horizontal lines' : 'headings of the chosen kind') . ' were found, so the file is one chapter.';
        }

        $chapters = [];

        foreach ($groups as $group) {
            // A scene break at the very start or end of a chapter is only a leftover of the way the file was cut.
            $html = trim(implode("\n", array_column($group['blocks'], 'html')));
            $html = trim((string) preg_replace('~^(?:<hr>\s*)+|(?:\s*<hr>)+\z~', '', $html));

            if (WordCounter::count($html, ContentFormat::Html) === 0) {
                continue;
            }

            $chapters[] = new OtwChapter(count($chapters) + 1, $group['title'], '', '', '', $html);
        }

        return $chapters;
    }

    private function language(DOMDocument $dom): string
    {
        $root = $dom->documentElement;
        $lang = $root instanceof DOMElement ? strtolower(trim($root->getAttribute('lang'))) : '';

        return preg_match('/^([a-z]{2,3})(?:[-_][a-z0-9]+)?\z/', $lang, $match) === 1 ? $match[1] : '';
    }

    private function titleFromFile(string $fileName): string
    {
        $stem = pathinfo($fileName, PATHINFO_FILENAME);
        $stem = trim((string) preg_replace('/[\s_]+/u', ' ', str_replace(['-'], ' ', $stem)));

        return $stem !== '' ? $stem : 'Untitled story';
    }

    private function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }
}
