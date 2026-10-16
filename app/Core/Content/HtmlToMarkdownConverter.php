<?php

/**
 * Converts the sanitised HTML the visual editor produces back into Markdown.
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
 * The reverse of MarkdownParser, so a chapter can switch editing modes without losing its formatting.
 * It only needs to understand what HtmlSanitizer lets through. Text is escaped so that a sentence which
 * happens to contain an asterisk or start with a number and a full stop comes back as the same sentence.
 */
final class HtmlToMarkdownConverter
{
    private const ALIGN_PREFIX = 'lf-align-';

    public function toMarkdown(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="lf-convert-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $root = $dom->getElementById('lf-convert-root');

        if ($root === null) {
            return '';
        }

        $markdown = $this->blocks($root);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $markdown));
    }

    /**
     * Block-level children joined by blank lines; stray inline content between blocks becomes a paragraph.
     */
    private function blocks(DOMNode $parent): string
    {
        $out = '';
        $inline = '';

        $flush = function () use (&$out, &$inline): void {
            if (trim($inline) !== '') {
                $out .= $this->escapeLineStarts(trim($inline)) . "\n\n";
            }

            $inline = '';
        };

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'ul', 'ol', 'pre', 'hr'], true)) {
                $flush();
                $out .= $this->block($child);

                continue;
            }

            $inline .= $this->inline($child);
        }

        $flush();

        return $out;
    }

    private function block(DOMElement $element): string
    {
        $tag = strtolower($element->tagName);

        return match (true) {
            $tag === 'p' => $this->paragraph($element),
            $tag === 'hr' => "---\n\n",
            $tag === 'pre' => $this->codeBlock($element),
            $tag === 'blockquote' => $this->quote($element),
            $tag === 'ul', $tag === 'ol' => $this->listBlock($element, 0) . "\n",
            default => str_repeat('#', (int) $tag[1]) . ' ' . trim($this->children($element)) . $this->alignSuffix($element) . "\n\n",
        };
    }

    private function paragraph(DOMElement $p): string
    {
        $text = trim($this->children($p));

        if ($text === '') {
            return '';
        }

        // The alignment marker belongs to the last line, which is where the parser looks for it.
        return $this->escapeLineStarts($text) . $this->alignSuffix($p) . "\n\n";
    }

    private function quote(DOMElement $quote): string
    {
        $inner = trim($this->blocks($quote));
        $lines = array_map(static fn (string $line): string => $line === '' ? '>' : '> ' . $line, explode("\n", $inner));

        return implode("\n", $lines) . "\n\n";
    }

    private function codeBlock(DOMElement $pre): string
    {
        $code = $pre->textContent;
        $language = '';
        $first = $pre->getElementsByTagName('code')->item(0);

        foreach ([$first, $pre] as $source) {
            if ($source instanceof DOMElement && preg_match('/\blanguage-([A-Za-z0-9+#-]{1,20})\b/', $source->getAttribute('class'), $m) === 1) {
                $language = $m[1];

                break;
            }
        }

        // A fence one backtick longer than the longest run inside the code cannot be closed early by it.
        $longest = 0;

        if (preg_match_all('/\x60+/', $code, $runs) > 0) {
            $longest = max(array_map('strlen', $runs[0]));
        }

        $fence = str_repeat('`', max(3, $longest + 1));

        return $fence . $language . "\n" . rtrim($code, "\n") . "\n" . $fence . "\n\n";
    }

    private function listBlock(DOMElement $list, int $depth): string
    {
        $ordered = strtolower($list->tagName) === 'ol';
        $number = 1;
        $out = '';

        foreach ($list->childNodes as $item) {
            if (!$item instanceof DOMElement || strtolower($item->tagName) !== 'li') {
                continue;
            }

            $text = '';
            $nested = '';

            foreach ($item->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['ul', 'ol'], true)) {
                    $nested .= $this->listBlock($child, $depth + 1);
                } elseif ($child instanceof DOMElement && strtolower($child->tagName) === 'p') {
                    $text .= $this->children($child) . ' ';
                } else {
                    $text .= $this->inline($child);
                }
            }

            $marker = $ordered ? $number++ . '. ' : '- ';
            $out .= str_repeat('    ', $depth) . $marker . trim((string) preg_replace('/\s*\n\s*/', ' ', $text)) . "\n" . $nested;
        }

        return $out;
    }

    private function children(DOMNode $node): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            $out .= $this->inline($child);
        }

        return $out;
    }

    private function inline(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            $text = preg_replace('/\s+/', ' ', $node->nodeValue ?? '') ?? '';

            // The newline after a line break is layout, not a space the author typed.
            if ($node->previousSibling instanceof DOMElement && strtolower($node->previousSibling->tagName) === 'br') {
                $text = ltrim($text);
            }

            return $this->escapeText($text);
        }

        if (!$node instanceof DOMElement) {
            return '';
        }

        $inner = $this->children($node);

        return match (strtolower($node->tagName)) {
            'strong', 'b' => $this->wrap('**', $inner),
            'em', 'i' => $this->wrap('*', $inner),
            'del', 's' => $this->wrap('~~', $inner),
            'u', 'sup', 'sub' => $inner === '' ? '' : '<' . strtolower($node->tagName) . '>' . $inner . '</' . strtolower($node->tagName) . '>',
            'br' => "  \n",
            'code' => $this->codeSpan($node->textContent),
            'a' => $this->link($node, $inner),
            default => $inner,
        };
    }

    /**
     * Emphasis markers must hug the text, so any spaces at its edges move outside them.
     */
    private function wrap(string $marker, string $inner): string
    {
        if (trim($inner) === '') {
            return $inner;
        }

        preg_match('/^(\s*)(.*?)(\s*)\z/s', $inner, $m);

        return $m[1] . $marker . $m[2] . $marker . $m[3];
    }

    private function codeSpan(string $code): string
    {
        $longest = 0;

        if (preg_match_all('/\x60+/', $code, $runs) > 0) {
            $longest = max(array_map('strlen', $runs[0]));
        }

        $fence = str_repeat('`', $longest + 1);
        $pad = $code !== '' && ($code[0] === '`' || str_ends_with($code, '`')) ? ' ' : '';

        return $fence . $pad . $code . $pad . $fence;
    }

    private function link(DOMElement $a, string $inner): string
    {
        $href = $a->getAttribute('href');

        if ($href === '') {
            return $inner;
        }

        // Parentheses and spaces in an address would end the Markdown link early.
        $href = str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $href);

        return '[' . $inner . '](' . $href . ')';
    }

    private function alignSuffix(DOMElement $element): string
    {
        foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [] as $class) {
            if (str_starts_with($class, self::ALIGN_PREFIX)) {
                $align = substr($class, strlen(self::ALIGN_PREFIX));

                if (in_array($align, ['left', 'center', 'right', 'justify'], true)) {
                    return ' {.' . $align . '}';
                }
            }
        }

        return '';
    }

    private function escapeText(string $text): string
    {
        return (string) preg_replace('/([\\\\\x60*_\[\]<~{}])/', '\\\\$1', $text);
    }

    /**
     * Escapes a marker a line would otherwise start with (a heading, quote, list item or rule).
     */
    private function escapeLineStarts(string $text): string
    {
        return implode("\n", array_map(static function (string $line): string {
            $line = (string) preg_replace('/^(\s*)([#>+-])/', '$1\\\\$2', $line);

            return (string) preg_replace('/^(\s*\d{1,9})([.)])/', '$1\\\\$2', $line);
        }, explode("\n", $text)));
    }
}
