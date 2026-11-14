<?php

/**
 * Takes the files a person uploaded for an import (HTML, EPUB or a ZIP of HTML files) and hands back the HTML of each work.
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

use LumoraFiction\Core\Http\UploadedFile;
use ZipArchive;

/**
 * Nothing in an uploaded file is trusted: sizes are capped before and after reading, a ZIP is read entry by entry in
 * memory and never extracted to disk, and an entry whose name could point outside it, a link, or another archive is
 * refused. A file that is not valid UTF-8 and does not declare a Western charset is refused rather than saved as
 * broken text.
 */
final class UploadReader
{
    public const MAX_FILES = 20;

    public const MAX_FILE_BYTES = 5_242_880;

    public const MAX_ZIP_BYTES = 20_971_520;

    public const MAX_EXPANDED_BYTES = 41_943_040;

    public const MAX_ENTRIES = 100;

    public static function zipAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * @param list<UploadedFile> $uploads
     * @return list<array{name: string, html: ?string, error: string}> one row per HTML file found, or per file refused
     */
    public function read(array $uploads): array
    {
        $rows = [];
        $expanded = 0;

        foreach ($uploads as $upload) {
            if (count($rows) >= self::MAX_FILES) {
                $rows[] = ['name' => '', 'html' => null, 'error' => 'Only ' . self::MAX_FILES . ' files can be imported at once. The rest were not read.'];

                break;
            }

            $problem = $upload->problem();

            if ($problem !== '') {
                $rows[] = ['name' => '', 'html' => null, 'error' => $problem];

                continue;
            }

            $head = $this->head($upload->path);

            if ($head === "PK\x03\x04" && EpubReader::isEpub($upload->path)) {
                $rows[] = $this->fromEpub($upload);

                continue;
            }

            if ($head === "PK\x03\x04") {
                array_push($rows, ...$this->fromZip($upload, $expanded, self::MAX_FILES - count($rows)));

                continue;
            }

            if ($upload->size > self::MAX_FILE_BYTES) {
                $rows[] = ['name' => '', 'html' => null, 'error' => 'A file is larger than ' . (int) (self::MAX_FILE_BYTES / 1048576) . ' MB, which is more than a story download should be.'];

                continue;
            }

            $raw = file_get_contents($upload->path, false, null, 0, self::MAX_FILE_BYTES + 1);
            $rows[] = $this->row('', $raw === false ? '' : $raw);
        }

        return $rows;
    }

    private function head(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        $head = (string) fread($handle, 4);
        fclose($handle);

        return $head;
    }

    /**
     * @return array{name: string, html: ?string, error: string}
     */
    private function fromEpub(UploadedFile $upload): array
    {
        if ($upload->size > self::MAX_ZIP_BYTES) {
            return ['name' => '', 'html' => null, 'error' => 'That EPUB file is larger than ' . (int) (self::MAX_ZIP_BYTES / 1048576) . ' MB.'];
        }

        try {
            return ['name' => '', 'html' => (new EpubReader())->toHtml($upload->path), 'error' => ''];
        } catch (ImportException $exception) {
            return ['name' => '', 'html' => null, 'error' => $exception->getMessage()];
        }
    }

    /**
     * @return list<array{name: string, html: ?string, error: string}>
     */
    private function fromZip(UploadedFile $upload, int &$expanded, int $room): array
    {
        if (!self::zipAvailable()) {
            return [['name' => '', 'html' => null, 'error' => 'This server cannot open ZIP files. Upload the HTML files one at a time.']];
        }

        if ($upload->size > self::MAX_ZIP_BYTES) {
            return [['name' => '', 'html' => null, 'error' => 'That ZIP file is larger than ' . (int) (self::MAX_ZIP_BYTES / 1048576) . ' MB.']];
        }

        $zip = new ZipArchive();

        if ($zip->open($upload->path, ZipArchive::RDONLY) !== true) {
            return [['name' => '', 'html' => null, 'error' => 'That ZIP file could not be opened.']];
        }

        $rows = [];

        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();

            return [['name' => '', 'html' => null, 'error' => 'That ZIP file holds too many files (more than ' . self::MAX_ENTRIES . ').']];
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if ($name === '' || str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || str_starts_with(basename($name), '.')) {
                continue;
            }

            $label = $this->label($name);

            if ($this->unsafeName($name) || $this->isLink($zip, $index)) {
                $rows[] = ['name' => $label, 'html' => null, 'error' => 'This entry was refused because of its name or type.'];

                continue;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!in_array($extension, ['html', 'htm'], true)) {
                $rows[] = ['name' => $label, 'html' => null, 'error' => in_array($extension, ['zip', 'epub'], true) ? 'Archives inside archives are not opened. Upload an EPUB file on its own.' : 'Only the HTML or EPUB download of a work can be imported.'];

                continue;
            }

            if (count($rows) >= $room) {
                $rows[] = ['name' => '', 'html' => null, 'error' => 'Only ' . self::MAX_FILES . ' files can be imported at once. The rest were not read.'];

                break;
            }

            $stat = $zip->statIndex($index);
            $size = is_array($stat) ? (int) $stat['size'] : 0;

            if ($size > self::MAX_FILE_BYTES || $expanded + $size > self::MAX_EXPANDED_BYTES) {
                $rows[] = ['name' => $label, 'html' => null, 'error' => 'This file is too large to import.'];

                continue;
            }

            $raw = $zip->getFromIndex($index, self::MAX_FILE_BYTES + 1);
            $expanded += is_string($raw) ? strlen($raw) : 0;
            $rows[] = $this->row($label, is_string($raw) ? $raw : '');
        }

        $zip->close();

        return $rows;
    }

    /**
     * @return array{name: string, html: ?string, error: string}
     */
    private function row(string $name, string $raw): array
    {
        if ($raw === '' || strlen($raw) > self::MAX_FILE_BYTES) {
            return ['name' => $name, 'html' => null, 'error' => $raw === '' ? 'This file is empty.' : 'This file is too large to import.'];
        }

        $raw = (string) preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        if (!mb_check_encoding($raw, 'UTF-8')) {
            // A Western charset the page itself declares is converted; anything else is refused, never saved as broken text.
            if (preg_match('/charset\s*=\s*["\']?(?:iso-8859-1|iso-8859-15|windows-1252|latin1)/i', substr($raw, 0, 4096)) !== 1) {
                return ['name' => $name, 'html' => null, 'error' => 'This file is not UTF-8 text. Download the work again as HTML and upload that file.'];
            }

            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        if (str_contains($raw, "\0")) {
            return ['name' => $name, 'html' => null, 'error' => 'This does not look like an HTML file.'];
        }

        return ['name' => $name, 'html' => $raw, 'error' => ''];
    }

    private function unsafeName(string $name): bool
    {
        return str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('/^[A-Za-z]:/', $name) === 1 || preg_match('/[\x00-\x1f]/', $name) === 1;
    }

    private function isLink(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attributes = 0;

        if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return false;
        }

        return $opsys === ZipArchive::OPSYS_UNIX && ((($attributes >> 16) & 0170000) === 0120000);
    }

    private function label(string $name): string
    {
        return mb_substr((string) preg_replace('/[\x00-\x1f\x7f]/', '', basename($name)), 0, 120, 'UTF-8');
    }
}
