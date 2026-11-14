<?php

/**
 * Reads the EPUB download of a work and joins its pages into the one document the download parser reads.
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

use DOMDocument;
use DOMElement;
use ZipArchive;

/**
 * The converter that makes the EPUB splits the work over several pages (the preface alone can be in two) and renames the
 * classes, but it keeps the ids and the order of the pages, so the pages' contents are joined in spine order and the same
 * parser reads the result. Everything is read in memory, entry by entry: nothing is extracted to disk, a name that could
 * point outside the archive is refused, and the package, the pages and the total size are capped.
 */
final class EpubReader
{
    public const MAX_PAGES = 200;

    public const MAX_PAGE_BYTES = 5_242_880;

    public const MAX_TOTAL_BYTES = 20_971_520;

    /**
     * Whether the file is an EPUB, so a ZIP of HTML files and an EPUB are told apart.
     */
    public static function isEpub(string $path): bool
    {
        if (!class_exists(ZipArchive::class)) {
            return false;
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        $mime = $zip->getFromName('mimetype', 64);
        $found = (is_string($mime) && str_starts_with(trim($mime), 'application/epub+zip')) || $zip->locateName('META-INF/container.xml') !== false;
        $zip->close();

        return $found;
    }

    /**
     * @return string one HTML document holding every page of the book, in reading order
     * @throws ImportException when the file cannot be read as an EPUB
     */
    public function toHtml(string $path): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ImportException('This server cannot open EPUB files. Download the work as HTML instead.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true || $zip->numFiles > 400) {
            throw new ImportException('That EPUB file could not be opened.');
        }

        try {
            $package = $this->packagePath($zip);
            $pages = $this->spine($zip, $package);
            $body = '';
            $total = 0;

            foreach (array_slice($pages, 0, self::MAX_PAGES) as $page) {
                $raw = $zip->getFromName($page, self::MAX_PAGE_BYTES + 1);

                if (!is_string($raw) || $raw === '') {
                    continue;
                }

                $total += strlen($raw);

                if (strlen($raw) > self::MAX_PAGE_BYTES || $total > self::MAX_TOTAL_BYTES) {
                    throw new ImportException('That EPUB file is too large to import.');
                }

                if (!mb_check_encoding($raw, 'UTF-8')) {
                    throw new ImportException('That EPUB file is not UTF-8 text. Download the work as HTML instead.');
                }

                $body .= $this->bodyOf($raw);
            }
        } finally {
            $zip->close();
        }

        if (trim($body) === '') {
            throw new ImportException('That EPUB file holds no text to import.');
        }

        return '<!DOCTYPE html><html><body>' . $body . '</body></html>';
    }

    private function packagePath(ZipArchive $zip): string
    {
        $container = $zip->getFromName('META-INF/container.xml', 65536);
        $dom = $this->load(is_string($container) ? $container : '');
        $root = $dom?->getElementsByTagName('rootfile')->item(0);
        $path = $root instanceof DOMElement ? $root->getAttribute('full-path') : '';

        if ($path === '' || $this->unsafe($path)) {
            throw new ImportException('That EPUB file could not be read: it names no book.');
        }

        return $path;
    }

    /**
     * @return list<string> the archive paths of the pages in reading order
     */
    private function spine(ZipArchive $zip, string $package): array
    {
        $opf = $zip->getFromName($package, 524288);
        $dom = $this->load(is_string($opf) ? $opf : '');

        if ($dom === null) {
            throw new ImportException('That EPUB file could not be read: its book description is damaged.');
        }

        $directory = str_contains($package, '/') ? substr($package, 0, (int) strrpos($package, '/') + 1) : '';
        $manifest = [];

        foreach ($dom->getElementsByTagName('item') as $item) {
            $type = $item->getAttribute('media-type');

            if ($item->getAttribute('id') !== '' && in_array($type, ['application/xhtml+xml', 'text/html'], true)) {
                $manifest[$item->getAttribute('id')] = $item->getAttribute('href');
            }
        }

        $pages = [];

        foreach ($dom->getElementsByTagName('itemref') as $reference) {
            $href = $manifest[$reference->getAttribute('idref')] ?? null;

            if ($href === null) {
                continue;
            }

            $path = $directory . rawurldecode((string) strtok($href, '#'));

            if ($this->unsafe($path) || $zip->locateName($path) === false) {
                continue;
            }

            $pages[] = $path;
        }

        if ($pages === []) {
            throw new ImportException('That EPUB file could not be read: it lists no pages.');
        }

        return $pages;
    }

    private function bodyOf(string $page): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $page, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0);
        $html = '';

        if ($body === null) {
            return '';
        }

        foreach ($body->childNodes as $child) {
            $html .= $dom->saveHTML($child);
        }

        return $html;
    }

    private function load(string $xml): ?DOMDocument
    {
        if ($xml === '') {
            return null;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $dom : null;
    }

    private function unsafe(string $name): bool
    {
        return str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('/^[A-Za-z]:/', $name) === 1 || preg_match('/[\x00-\x1f]/', $name) === 1;
    }
}
