<?php

/**
 * Converts Markdown source to HTML.
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

/**
 * A small, dependency-free parser for the part of Markdown that fiction uses: paragraphs, headings,
 * scene-break rules, quotations, nested lists, fenced code, links, and bold, italic and strikethrough
 * text. Raw HTML in the source is never passed through: a script typed into the Markdown box is printed
 * as text. The output is still run through HtmlSanitizer, as defence in depth.
 *
 * A trailing {.left}, {.center}, {.right} or {.justify} on a paragraph or heading aligns it, using the
 * same classes the visual editor applies. The tiny set of tags Markdown cannot express (underline,
 * superscript, subscript) is written as <u>, <sup> and <sub> and recognised after escaping.
 */
final class MarkdownParser
{
    private const ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    /** @var array<int, string> */
    private array $placeholders = [];

    public function toHtml(string $markdown): string
    {
        $this->placeholders = [];

        // The placeholder delimiters are control characters; removing them from the input is what stops
        // typed text from forging a placeholder.
        $markdown = str_replace(["\x02", "\x03", "\x04"], '', $markdown);
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

        return trim($this->renderBlocks(explode("\n", $markdown)));
    }

    /**
     * @param list<string> $lines
     */
    private function renderBlocks(array $lines): string
    {
        $html = '';
        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;

                continue;
            }

            if (preg_match('/^ {0,3}(\x60{3,}|~{3,})\s*([A-Za-z0-9_+#-]*)\s*\z/', $line, $m) === 1) {
                $fence = $m[1];
                $code = [];
                $i++;

                while ($i < $count && preg_match('/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*\z/', $lines[$i]) !== 1) {
                    $code[] = $lines[$i];
                    $i++;
                }

                $i++;
                $class = $m[2] !== '' ? ' class="language-' . htmlspecialchars($m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '';
                $html .= '<pre><code' . $class . '>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</code></pre>\n";

                continue;
            }

            if (preg_match('/^ {0,3}(#{1,6})\s+(.*?)\s*#*\s*\z/', $line, $m) === 1) {
                [$text, $align] = $this->splitAlignment($m[2]);
                $level = strlen($m[1]);
                $html .= "<h{$level}{$this->alignClass($align)}>" . $this->inline($text) . "</h{$level}>\n";
                $i++;

                continue;
            }

            if ($this->isRule($line)) {
                $html .= "<hr>\n";
                $i++;

                continue;
            }

            if (preg_match('/^ {0,3}>/', $line) === 1) {
                $quoted = [];

                while ($i < $count && preg_match('/^ {0,3}>\s?(.*)\z/', $lines[$i], $m) === 1) {
                    $quoted[] = $m[1];
                    $i++;
                }

                $html .= '<blockquote>' . $this->renderBlocks($quoted) . "</blockquote>\n";

                continue;
            }

            if ($this->listMarker($line) !== null) {
                $items = [];

                while ($i < $count) {
                    $marker = $this->listMarker($lines[$i]);

                    if ($marker === null) {
                        // A blank line inside a list is allowed when another item follows it.
                        if (trim($lines[$i]) === '' && isset($lines[$i + 1]) && $this->listMarker($lines[$i + 1]) !== null) {
                            $i++;

                            continue;
                        }

                        // A deeper-indented line continues the previous item's text.
                        if ($items !== [] && trim($lines[$i]) !== '' && $this->indent($lines[$i]) > $items[count($items) - 1]['indent']) {
                            $items[count($items) - 1]['text'] .= ' ' . trim($lines[$i]);
                            $i++;

                            continue;
                        }

                        break;
                    }

                    $items[] = $marker;
                    $i++;
                }

                $position = 0;
                $html .= $this->renderList($items, $position);

                continue;
            }

            $paragraph = [$line];
            $i++;

            while ($i < $count && trim($lines[$i]) !== '' && !$this->startsBlock($lines[$i])) {
                $paragraph[] = $lines[$i];
                $i++;
            }

            [$paragraph[count($paragraph) - 1], $align] = $this->splitAlignment($paragraph[count($paragraph) - 1]);
            $html .= '<p' . $this->alignClass($align) . '>' . $this->inline($this->joinLines($paragraph)) . "</p>\n";
        }

        return $html;
    }

    private function startsBlock(string $line): bool
    {
        return preg_match('/^ {0,3}(?:#{1,6}\s|>|\x60{3,}|~{3,})/', $line) === 1
            || $this->isRule($line)
            || $this->listMarker($line) !== null;
    }

    private function isRule(string $line): bool
    {
        return preg_match('/^ {0,3}([-*_])( *\1){2,} *\z/', $line) === 1;
    }

    /**
     * @return array{indent: int, ordered: bool, text: string}|null
     */
    private function listMarker(string $line): ?array
    {
        if ($this->isRule($line) || preg_match('/^(\s*)([-*+]|\d{1,9}[.)])\s+(\S.*)\z/', $line, $m) !== 1) {
            return null;
        }

        return ['indent' => strlen(str_replace("\t", '    ', $m[1])), 'ordered' => ctype_digit($m[2][0]), 'text' => $m[3]];
    }

    private function indent(string $line): int
    {
        return strlen(str_replace("\t", '    ', $line)) - strlen(ltrim(str_replace("\t", '    ', $line)));
    }

    /**
     * @param list<array{indent: int, ordered: bool, text: string}> $items
     */
    private function renderList(array $items, int &$position): string
    {
        $level = $items[$position]['indent'];
        $tag = $items[$position]['ordered'] ? 'ol' : 'ul';
        $html = "<{$tag}>\n";

        while ($position < count($items) && $items[$position]['indent'] >= $level) {
            $text = $items[$position]['text'];
            $position++;
            $html .= '<li>' . $this->inline($text);

            if ($position < count($items) && $items[$position]['indent'] > $level) {
                $html .= "\n" . $this->renderList($items, $position);
            }

            $html .= "</li>\n";
        }

        return $html . "</{$tag}>\n";
    }

    /**
     * @param list<string> $lines
     */
    private function joinLines(array $lines): string
    {
        $out = '';

        foreach ($lines as $index => $line) {
            $hardBreak = preg_match('/(?: {2,}|\\\\)\z/', $line) === 1;
            $out .= rtrim($line, " \t\\");

            if ($index < count($lines) - 1) {
                $out .= $hardBreak ? "\x04" : ' ';
            }
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function splitAlignment(string $text): array
    {
        if (preg_match('/^(.*?)\s*\{\.(' . implode('|', self::ALIGNMENTS) . ')\}\s*\z/', $text, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return [$text, null];
    }

    private function alignClass(?string $align): string
    {
        return $align === null ? '' : ' class="lf-align-' . $align . '"';
    }

    private function inline(string $text): string
    {
        // Code spans come out first so nothing inside them is read as Markdown.
        $text = (string) preg_replace_callback('/(\x60+)(?!\x60)(.+?)(?<!\x60)\1(?!\x60)/s', fn (array $m): string => $this->hold('<code>' . htmlspecialchars(trim($m[2]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>'), $text);

        // A backslash makes the next punctuation character literal.
        $text = (string) preg_replace_callback('/\\\\([\\\\\x60*_\[\]()#+\-.!~<>{}|])/', fn (array $m): string => $this->hold(htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8')), $text);

        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $text = (string) preg_replace_callback('/\[([^\[\]]+)\]\(\s*([^\s)]+)(?:\s+&quot;([^&]*?)&quot;)?\s*\)/', function (array $m): string {
            $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');

            // An unsafe address is not turned into a link; the writer's text stays as typed.
            if (!$this->isSafeUrl($url)) {
                return $m[0];
            }

            $title = isset($m[3]) && $m[3] !== '' ? ' title="' . $m[3] . '"' : '';

            // The tags are held back so underscores in an address are not read as emphasis.
            return $this->hold('<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . $title . '>') . $m[1] . $this->hold('</a>');
        }, $text);

        $text = (string) preg_replace('/&lt;(u|sup|sub)&gt;(.+?)&lt;\/\1&gt;/s', '<$1>$2</$1>', $text);
        $text = (string) preg_replace('/\*\*\*(?=\S)(.+?)(?<=\S)\*\*\*/s', '<strong><em>$1</em></strong>', $text);
        $text = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![A-Za-z0-9])__(?=\S)(.+?)(?<=\S)__(?![A-Za-z0-9])/s', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/\*(?=\S)(.+?)(?<=\S)\*/s', '<em>$1</em>', $text);
        $text = (string) preg_replace('/(?<![A-Za-z0-9])_(?=\S)(.+?)(?<=\S)_(?![A-Za-z0-9])/s', '<em>$1</em>', $text);
        $text = (string) preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '<del>$1</del>', $text);
        $text = str_replace("\x04", "<br>\n", $text);

        // Held fragments can contain other held fragments (a code span inside link text), so restore until none are left.
        while (preg_match('/\x02(\d+)\x03/', $text) === 1) {
            $text = (string) preg_replace_callback('/\x02(\d+)\x03/', fn (array $m): string => $this->placeholders[(int) $m[1]] ?? '', $text);
        }

        return $text;
    }

    private function hold(string $html): string
    {
        $this->placeholders[] = $html;

        return "\x02" . (count($this->placeholders) - 1) . "\x03";
    }

    private function isSafeUrl(string $url): bool
    {
        $url = trim((string) preg_replace('/[\x00-\x20\x7f]+/', '', $url));

        if ($url === '' || str_starts_with($url, '//') || str_starts_with($url, '\\')) {
            return false;
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $url, $m) !== 1) {
            return true;
        }

        return in_array(strtolower(rtrim($m[0], ':')), ['http', 'https', 'mailto'], true);
    }
}
