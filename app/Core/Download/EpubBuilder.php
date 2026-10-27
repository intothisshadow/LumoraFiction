<?php

/**
 * The EPUB download: a valid EPUB 3 with a cover, metadata, a navigation document and one file per chapter.
 *
 * @package LumoraFiction
 * @subpackage Download
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Download;

use DOMDocument;
use DOMNode;
use ZipArchive;

final class EpubBuilder
{
    private const COVER_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const STYLE = <<<'CSS'
body{font-family:serif;line-height:1.5;margin:0 5%}
h1,h2{text-align:center;line-height:1.25}
h1{margin:2em 0 .25em}
.byline{text-align:center;font-style:italic;margin:0 0 2em}
dl{margin:0 0 1.5em}dt{font-weight:bold}dd{margin:0 0 .5em}
p{margin:0 0 .8em}
blockquote{margin:1em 0 1em 1.5em;font-style:italic}
hr{border:0;border-top:1px solid #888;margin:2em auto;width:30%}
pre{white-space:pre-wrap}
.notes{padding:.25em 1em;border-left:3px solid #888;font-size:.95em}
.cover{text-align:center;margin:0}
.cover img{max-width:100%;max-height:100%}
.lf-align-left{text-align:left}.lf-align-center{text-align:center}.lf-align-right{text-align:right}.lf-align-justify{text-align:justify}
CSS;

    public static function isAvailable(): bool
    {
        return class_exists(ZipArchive::class) && class_exists(DOMDocument::class);
    }

    /**
     * Writes the book to $target. The caller moves it into place, so a half-written file is never served.
     *
     * @throws DownloadException
     */
    public function build(StoryDocument $document, string $target): void
    {
        if (!self::isAvailable()) {
            throw new DownloadException('This server cannot make EPUB files.');
        }

        $zip = new ZipArchive();

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new DownloadException('The file could not be made. Please try again later.');
        }

        // The mimetype entry must come first and be stored without compression, which is how readers recognise an EPUB.
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
        $zip->addFromString('META-INF/container.xml', $this->container());

        $cover = $this->coverExtension($document);
        $items = [];
        $spine = [];

        if ($cover !== null) {
            $zip->addFile($document->coverPath, 'OEBPS/cover.' . $cover);
            $zip->addFromString('OEBPS/cover.xhtml', $this->page($document, 'Cover', '<p class="cover"><img src="cover.' . $cover . '" alt="' . $this->e($document->coverAlt !== '' ? $document->coverAlt : 'Cover of ' . $document->title) . '"/></p>'));
            $items[] = '<item id="cover-image" href="cover.' . $cover . '" media-type="' . array_search($cover, self::COVER_TYPES, true) . '" properties="cover-image"/>';
            $items[] = '<item id="cover" href="cover.xhtml" media-type="application/xhtml+xml"/>';
            // An ordinary first page: a non-linear page must be linked from somewhere, and readers show the cover first anyway.
            $spine[] = '<itemref idref="cover"/>';
        }

        $zip->addFromString('OEBPS/style.css', self::STYLE);
        $zip->addFromString('OEBPS/title.xhtml', $this->page($document, $document->title, $this->titlePage($document)));
        $items[] = '<item id="style" href="style.css" media-type="text/css"/>';
        $items[] = '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>';
        $items[] = '<item id="title" href="title.xhtml" media-type="application/xhtml+xml"/>';
        $spine[] = '<itemref idref="title"/>';

        $navigation = [];

        foreach ($document->chapters as $chapter) {
            $name = sprintf('chapter-%03d.xhtml', $chapter['number']);
            $zip->addFromString('OEBPS/' . $name, $this->page($document, $chapter['title'], $this->chapterBody($chapter)));
            $items[] = '<item id="c' . $chapter['number'] . '" href="' . $name . '" media-type="application/xhtml+xml"/>';
            $spine[] = '<itemref idref="c' . $chapter['number'] . '"/>';
            $navigation[] = '<li><a href="' . $name . '">' . $this->e($chapter['title']) . '</a></li>';
        }

        $zip->addFromString('OEBPS/nav.xhtml', $this->navigation($document, $navigation));
        $zip->addFromString('OEBPS/content.opf', $this->package($document, $items, $spine, $cover !== null));

        if (!$zip->close()) {
            throw new DownloadException('The file could not be made. Please try again later.');
        }
    }

    private function container(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles>'
            . '<rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>';
    }

    /**
     * @param list<string> $items
     * @param list<string> $spine
     */
    private function package(StoryDocument $document, array $items, array $spine, bool $hasCover): string
    {
        $hash = md5('lumora-fiction-story-' . $document->storyId);
        $identifier = 'urn:uuid:' . substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
        $subjects = '';

        foreach ($document->details as $detail) {
            if (in_array($detail['label'], ['Fandoms', 'Genres', 'Tags'], true)) {
                foreach ($detail['values'] as $value) {
                    $subjects .= '<dc:subject>' . $this->e($value) . '</dc:subject>';
                }
            }
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="book-id" xml:lang="' . $this->e($document->language) . '">'
            . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:identifier id="book-id">' . $identifier . '</dc:identifier>'
            . '<dc:title>' . $this->e($document->title) . '</dc:title>'
            . '<dc:creator>' . $this->e($document->author) . '</dc:creator>'
            . '<dc:language>' . $this->e($document->language) . '</dc:language>'
            . ($document->summary === '' ? '' : '<dc:description>' . $this->e($document->summary) . '</dc:description>')
            . $subjects
            . '<meta property="dcterms:modified">' . gmdate('Y-m-d\TH:i:s\Z', $document->updatedAt) . '</meta>'
            . ($hasCover ? '<meta name="cover" content="cover-image"/>' : '')
            . '</metadata><manifest>' . implode('', $items) . '</manifest><spine>' . implode('', $spine) . '</spine></package>';
    }

    /**
     * @param list<string> $entries
     */
    private function navigation(StoryDocument $document, array $entries): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="' . $this->e($document->language) . '" xml:lang="' . $this->e($document->language) . '">'
            . '<head><meta charset="utf-8"/><title>Contents</title><link rel="stylesheet" type="text/css" href="style.css"/></head>'
            . '<body><nav epub:type="toc" id="toc"><h1>Contents</h1><ol><li><a href="title.xhtml">' . $this->e($document->title) . '</a></li>' . implode('', $entries) . '</ol></nav></body></html>';
    }

    private function titlePage(StoryDocument $document): string
    {
        $out = '<h1>' . $this->e($document->title) . '</h1><p class="byline">by ' . $this->e($document->author) . '</p><dl>';

        foreach ($document->details as $detail) {
            $out .= '<dt>' . $this->e($detail['label']) . '</dt><dd>' . $this->e(implode(', ', $detail['values'])) . '</dd>';
        }

        $out .= '<dt>Status</dt><dd>' . $this->e($document->status) . '</dd><dt>Words</dt><dd>' . number_format($document->words) . '</dd></dl>';

        foreach (['Summary' => $document->summary, 'Notes' => $document->notes, 'Disclaimer' => $document->disclaimer] as $heading => $text) {
            $out .= trim($text) === '' ? '' : '<h2>' . $heading . '</h2>' . $this->lines($text);
        }

        return $out;
    }

    /**
     * @param array{number: int, title: string, notes: string, html: string} $chapter
     */
    private function chapterBody(array $chapter): string
    {
        return '<h2>' . $this->e($chapter['title']) . '</h2>'
            . ($chapter['notes'] === '' ? '' : '<div class="notes">' . $this->lines($chapter['notes']) . '</div>')
            . ($chapter['html'] === '' ? '<p></p>' : $this->xhtml($chapter['html']));
    }

    private function page(StoryDocument $document, string $title, string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml" lang="' . $this->e($document->language) . '" xml:lang="' . $this->e($document->language) . '">'
            . '<head><meta charset="utf-8"/><title>' . $this->e($title) . '</title><link rel="stylesheet" type="text/css" href="style.css"/></head>'
            . '<body>' . $body . '</body></html>';
    }

    /**
     * Turns the cleaned chapter HTML into well-formed XML, which an EPUB reader needs and HTML does not guarantee
     * (an unclosed break or a bare ampersand would make the whole chapter unreadable).
     */
    private function xhtml(string $html): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"?><body>' . $html . '</body>', LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0);
        $out = '';

        if ($body instanceof DOMNode) {
            foreach ($body->childNodes as $child) {
                $out .= (string) $dom->saveXML($child);
            }
        }

        return $out === '' ? '<p></p>' : $out;
    }

    private function lines(string $text): string
    {
        $out = '';

        foreach (preg_split('/\R{2,}/', trim($text)) ?: [] as $paragraph) {
            $out .= '<p>' . implode('<br/>', array_map($this->e(...), preg_split('/\R/', $paragraph) ?: [])) . '</p>';
        }

        return $out;
    }

    /** The file extension for a usable cover, or null when the story has none or it is a type EPUB readers may not show. */
    private function coverExtension(StoryDocument $document): ?string
    {
        return $document->coverPath !== '' && is_file($document->coverPath) ? (self::COVER_TYPES[$document->coverType] ?? null) : null;
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
