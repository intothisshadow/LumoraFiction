<?php

/**
 * Reads the HTML download of a work from an OTW-Archive site (Archive of Our Own, Squidgeworld and the others).
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

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Every site running the OTW's Archive software builds this file from the same templates, so the parser looks for the
 * template's own landmarks (the preface, the tag list, the chapters block) and not for the name of the site. The labels
 * in the tag list are the English ones the templates print; a file in another language is refused rather than guessed
 * at. The text is read as data only: nothing in it is followed, fetched or run.
 */
final class OtwDownloadParser implements WorkParser
{
    private bool $strictHeadings = false;

    /** @var array<string, string> the language names the downloads print, with the code this site uses */
    private const LANGUAGES = [
        'english' => 'en', 'spanish' => 'es', 'french' => 'fr', 'german' => 'de', 'italian' => 'it', 'portuguese' => 'pt',
        'russian' => 'ru', 'japanese' => 'ja', 'korean' => 'ko', 'dutch' => 'nl', 'swedish' => 'sv', 'finnish' => 'fi',
        'polish' => 'pl', 'turkish' => 'tr', 'norwegian' => 'no', 'danish' => 'da', 'czech' => 'cs', 'hungarian' => 'hu',
        'indonesian' => 'id', 'vietnamese' => 'vi', 'arabic' => 'ar', 'hebrew' => 'he', 'greek' => 'el', 'latin' => 'la',
        'ukrainian' => 'uk', 'catalan' => 'ca', 'romanian' => 'ro', 'thai' => 'th', 'filipino' => 'fil', 'chinese' => 'zh',
        // The names a site may print in the language itself.
        'suomi' => 'fi', 'deutsch' => 'de', 'español' => 'es', 'français' => 'fr', 'italiano' => 'it', 'português' => 'pt',
        'русский' => 'ru', '日本語' => 'ja', '한국어' => 'ko', 'nederlands' => 'nl', 'svenska' => 'sv', 'polski' => 'pl',
        'türkçe' => 'tr', 'norsk' => 'no', 'dansk' => 'da', 'čeština' => 'cs', 'magyar' => 'hu', 'bahasa indonesia' => 'id',
        'tiếng việt' => 'vi', 'العربية' => 'ar', 'עברית' => 'he', 'ελληνικά' => 'el', 'українська' => 'uk', 'català' => 'ca',
        'română' => 'ro', 'ไทย' => 'th', 'eesti' => 'et', 'íslenska' => 'is',
    ];

    /**
     * Whether the file carries the template's own preface (title, byline and tag list), which is what makes its details
     * trustworthy. Anything else is read as a general HTML file.
     */
    public function recognises(string $html): bool
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        return $xpath->query("//*[@id='preface']//dl")->length > 0 && $xpath->query("//*[@id='preface']//h1")->length > 0;
    }

    /**
     * @throws ImportException when the file is not a download of a work, or holds no text
     */
    public function parse(string $html, string $fileName = ''): OtwWork
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $preface = $xpath->query("//*[@id='preface']")->item(0);
        // An EPUB splits the preface over several files, so the title, byline and summary may sit in a later element with the same id.
        $tags = $preface instanceof DOMElement ? $xpath->query("//*[@id='preface']//dl")->item(0) : null;
        $title = $preface instanceof DOMElement ? $xpath->query("//*[@id='preface']//h1")->item(0) : null;

        if (!$preface instanceof DOMElement || !$tags instanceof DOMElement || $title === null || $this->text($title) === '') {
            throw new ImportException('This file does not look like a download from an Archive of Our Own style site. Download the work as HTML (or EPUB) from its page and upload that file.');
        }

        $meta = $this->readTags($tags);
        $series = $this->series($meta['series']);
        $stats = $this->stats($meta['stats']);
        [$summary, $notes] = $this->prefaceText($dom, $xpath);
        $chapters = $this->chapters($dom, $xpath, $this->text($title));

        if ($chapters === []) {
            throw new ImportException('This download has no chapter text, so there is nothing to import.');
        }

        $source = $this->source($xpath);
        $byline = $xpath->query("//*[@id='preface']//div[contains(concat(' ', normalize-space(@class), ' '), ' byline ')]")->item(0);
        $byline = $byline === null ? '' : (string) preg_replace('/^by\s+/iu', '', $this->text($byline));
        $total = $stats['total'];
        $complete = $stats['completed'] || ($total !== null && $total === count($chapters));

        return new OtwWork(
            $fileName,
            $this->text($title),
            $byline,
            PlainText::fromHtml($summary),
            PlainText::fromHtml($notes),
            PlainText::fromHtml($this->endNotes($dom, $xpath)),
            $meta['rating'][0] ?? '',
            $meta['warning'],
            $meta['category'],
            $meta['fandom'],
            $meta['relationship'],
            $meta['character'],
            $meta['freeform'],
            self::LANGUAGES[mb_strtolower($meta['language'][0] ?? '', 'UTF-8')] ?? '',
            $series['name'],
            $series['part'],
            $meta['collection'],
            $stats['published'],
            $stats['updated'],
            $complete,
            $stats['words'],
            $source['url'],
            $source['id'],
            $chapters,
        );
    }

    /**
     * @return array{rating: list<string>, warning: list<string>, category: list<string>, fandom: list<string>, relationship: list<string>, character: list<string>, freeform: list<string>, language: list<string>, series: string, collection: list<string>, stats: string}
     */
    private function readTags(DOMElement $list): array
    {
        $found = ['rating' => [], 'warning' => [], 'category' => [], 'fandom' => [], 'relationship' => [], 'character' => [], 'freeform' => [], 'language' => [], 'series' => '', 'collection' => [], 'stats' => ''];
        $label = '';

        foreach ($list->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            if ($node->tagName === 'dt') {
                $label = mb_strtolower(rtrim($this->text($node), ": \t\n\r\0\x0B\u{00A0}"), 'UTF-8');

                continue;
            }

            if ($node->tagName !== 'dd' || $label === '') {
                continue;
            }

            $key = match (true) {
                $label === 'rating' || $label === 'ratings' => 'rating',
                str_starts_with($label, 'archive warning') || str_starts_with($label, 'warning') => 'warning',
                str_starts_with($label, 'categor') => 'category',
                str_starts_with($label, 'fandom') => 'fandom',
                str_starts_with($label, 'relationship') => 'relationship',
                str_starts_with($label, 'character') => 'character',
                str_starts_with($label, 'additional tag') || $label === 'tags' || $label === 'tag' => 'freeform',
                str_starts_with($label, 'language') => 'language',
                str_starts_with($label, 'series') => 'series',
                str_starts_with($label, 'collection') => 'collection',
                str_starts_with($label, 'stats') => 'stats',
                default => '',
            };

            $label = '';

            if ($key === '') {
                continue;
            }

            if ($key === 'series' || $key === 'stats') {
                $found[$key] = $this->text($node);

                continue;
            }

            $found[$key] = $this->names($node);
        }

        return $found;
    }

    /**
     * The linked names in a tag list, or the text split at commas when the list holds no links.
     *
     * @return list<string>
     */
    private function names(DOMElement $item): array
    {
        $names = [];

        foreach ($item->getElementsByTagName('a') as $link) {
            $names[] = $this->text($link);
        }

        if ($names === []) {
            $names = array_map('trim', explode(',', $this->text($item)));
        }

        return array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== '')));
    }

    /**
     * @return array{name: string, part: int}
     */
    private function series(string $text): array
    {
        if (preg_match('/Part\s+(\d+)\s+of\s+(.+?)(?:\s+and\s+Part\s+\d+\s+of\s+.+)?\z/isu', $text, $match) !== 1) {
            return ['name' => '', 'part' => 0];
        }

        return ['name' => trim($match[2]), 'part' => (int) $match[1]];
    }

    /**
     * @return array{published: ?DateTimeImmutable, updated: ?DateTimeImmutable, completed: bool, words: int, total: ?int}
     */
    private function stats(string $text): array
    {
        $date = static function (string $label) use ($text): ?DateTimeImmutable {
            if (preg_match('/' . $label . ':\s*(\d{4})-(\d{2})-(\d{2})/u', $text, $match) !== 1) {
                return null;
            }

            $made = DateTimeImmutable::createFromFormat('!Y-m-d', $match[1] . '-' . $match[2] . '-' . $match[3]);

            return $made === false ? null : $made;
        };

        $completed = $date('Completed');
        $words = preg_match('/Words:\s*([\d.,\x{00A0}\x{202F}\s]+)/u', $text, $match) === 1 ? (int) preg_replace('/\D/', '', $match[1]) : 0;
        $total = preg_match('/Chapters:\s*\d+\s*\/\s*(\d+|\?)/u', $text, $chapters) === 1 && $chapters[1] !== '?' ? (int) $chapters[1] : null;

        return [
            'published' => $date('Published'),
            'updated' => $completed ?? $date('Updated'),
            'completed' => $completed !== null,
            'words' => $words,
            'total' => $total,
        ];
    }

    /**
     * The summary and the notes as the source's HTML: a label paragraph followed by its quoted block, found by walking the
     * preface in order, so the classes the template (or an EPUB converter) put on the wrappers do not matter.
     *
     * @return array{0: string, 1: string}
     */
    private function prefaceText(DOMDocument $dom, DOMXPath $xpath): array
    {
        $found = $this->labelled($dom, $xpath, "//*[@id='preface']", ['summary', 'notes']);

        return [$found['summary'], $found['notes']];
    }

    /**
     * @param list<string> $labels lower-case label texts
     * @return array<string, string> label => inner HTML of the quoted block that follows it
     */
    private function labelled(DOMDocument $dom, DOMXPath $xpath, string $scope, array $labels): array
    {
        $found = array_fill_keys($labels, '');
        $pending = '';

        foreach ($xpath->query($scope . '//*[(self::p or self::blockquote) and not(ancestor::blockquote) and not(ancestor::dl)]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            if ($node->tagName === 'p') {
                $word = mb_strtolower($this->text($node), 'UTF-8');
                $pending = isset($found[$word]) ? $word : '';
            } elseif ($pending !== '') {
                $found[$pending] = $this->inner($dom, $node);
                $pending = '';
            }
        }

        return $found;
    }

    private function endNotes(DOMDocument $dom, DOMXPath $xpath): string
    {
        $block = $xpath->query("//*[@id='endnotes']//blockquote")->item(0);

        return $block instanceof DOMElement ? $this->inner($dom, $block) : '';
    }

    /**
     * @return array{url: string, id: string}
     */
    private function source(DOMXPath $xpath): array
    {
        $href = '';

        foreach ($xpath->query("//*[@id='preface']//a[@href]") ?: [] as $link) {
            if ($link instanceof DOMElement && preg_match('~^https?://[^/]+/works/\d+\b~i', trim($link->getAttribute('href'))) === 1) {
                $href = trim($link->getAttribute('href'));

                break;
            }
        }

        $parts = parse_url($href);

        if ($href === '' || !is_array($parts) || ($parts['host'] ?? '') === '') {
            return ['url' => '', 'id' => ''];
        }

        $id = preg_match('~^/works/(\d+)\b~', (string) ($parts['path'] ?? ''), $match) === 1 ? strtolower($parts['host']) . '/works/' . $match[1] : '';

        return ['url' => mb_substr($href, 0, 500, 'UTF-8'), 'id' => $id];
    }

    /**
     * Chapters are found by their headings ("Chapter 2: Title"), which every download prints, and not by the classes of the
     * wrappers: a converted EPUB renames those and leaves empty wrapper divs behind. Every element with the id "chapters"
     * counts, in order, since an EPUB splits the block over several files.
     *
     * @return list<OtwChapter>
     */
    private function chapters(DOMDocument $dom, DOMXPath $xpath, string $workTitle): array
    {
        $blocks = $xpath->query("//*[@id='chapters']");

        if ($blocks === false || $blocks->length === 0) {
            return [];
        }

        $chapters = [];
        $current = null;
        $plain = null;
        // The heading's own class (kept by the converter too) tells it from a heading the author wrote inside the text; without it
        // every heading that reads "Chapter N" counts.
        $this->strictHeadings = $xpath->query("//*[@id='chapters']//h2[contains(concat(' ', normalize-space(@class), ' '), ' heading ')]")->length > 0;

        foreach ($blocks as $block) {
            foreach ($block->childNodes as $node) {
                if (!$node instanceof DOMElement || $node->tagName !== 'div') {
                    continue;
                }

                if ($this->chapterHeading($xpath, $node) !== null) {
                    if ($current !== null) {
                        $chapters[] = $current;
                    }

                    $current = $this->chapterHead($dom, $xpath, $node, count($chapters) + 1);
                } elseif (str_starts_with($node->getAttribute('id'), 'endnotes')) {
                    $quote = $node->getElementsByTagName('blockquote')->item(0);

                    if ($current !== null && $quote instanceof DOMElement) {
                        $current['end_notes'] = $this->inner($dom, $quote);
                    }
                } elseif ($this->hasContent($node)) {
                    // A converted EPUB cuts a chapter's text at the headings inside it and reopens the wrapper on the next page,
                    // so every block of text up to the next chapter heading belongs to the chapter.
                    if ($current !== null) {
                        $current['html'] = (string) $current['html'] . $this->inner($dom, $node);
                    } else {
                        $plain = (string) $plain . $this->inner($dom, $node);
                    }
                }
            }
        }

        if ($current !== null) {
            $chapters[] = $current;
        }

        // A work of one chapter is printed without chapter headings: its text is the first block with text under the title.
        if ($chapters === []) {
            return $plain === null ? [] : [new OtwChapter(1, '', '', '', '', $plain)];
        }

        $made = [];

        foreach ($chapters as $chapter) {
            $made[] = new OtwChapter(
                $chapter['number'],
                $chapter['title'],
                PlainText::fromHtml($chapter['summary']),
                PlainText::fromHtml($chapter['notes']),
                PlainText::fromHtml($chapter['end_notes']),
                (string) ($chapter['html'] ?? ''),
            );
        }

        return $made;
    }

    private function chapterHeading(DOMXPath $xpath, DOMElement $group): ?DOMElement
    {
        $query = $this->strictHeadings ? ".//h2[contains(concat(' ', normalize-space(@class), ' '), ' heading ')]" : './/h2';

        foreach ($xpath->query($query, $group) ?: [] as $heading) {
            if ($heading instanceof DOMElement && preg_match('/^Chapter\s+\d+\b/iu', $this->text($heading)) === 1) {
                return $heading;
            }
        }

        return null;
    }

    private function hasContent(DOMElement $node): bool
    {
        return trim(strip_tags($node->textContent)) !== '' || $node->getElementsByTagName('img')->length > 0;
    }

    /**
     * @return array{number: int, title: string, summary: string, notes: string, end_notes: string, html: ?string}
     */
    private function chapterHead(DOMDocument $dom, DOMXPath $xpath, DOMElement $group, int $fallbackNumber): array
    {
        $heading = $this->chapterHeading($xpath, $group);
        $headingText = $heading === null ? '' : $this->text($heading);
        $number = $fallbackNumber;
        $title = $headingText;

        if (preg_match('/^Chapter\s+(\d+)\s*(?::\s*(.*))?\z/isu', $headingText, $match) === 1) {
            $number = (int) $match[1];
            $title = trim($match[2] ?? '');
        }

        $found = ['chapter summary' => '', 'chapter notes' => ''];
        $pending = '';

        foreach ($xpath->query('.//*[(self::p or self::blockquote) and not(ancestor::blockquote)]', $group) ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            if ($node->tagName === 'p') {
                $word = mb_strtolower($this->text($node), 'UTF-8');
                $pending = isset($found[$word]) ? $word : '';
            } elseif ($pending !== '') {
                $found[$pending] = $this->inner($dom, $node);
                $pending = '';
            }
        }

        return ['number' => $number, 'title' => $title, 'summary' => $found['chapter summary'], 'notes' => $found['chapter notes'], 'end_notes' => '', 'html' => null];
    }

    private function text(DOMNode $node): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $node->textContent));
    }

    private function inner(DOMDocument $dom, DOMNode $node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $dom->saveHTML($child);
        }

        return trim($html);
    }
}
