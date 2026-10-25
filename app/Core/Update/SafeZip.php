<?php

/**
 * Reads and unpacks a ZIP without ever letting an entry write outside its target folder.
 *
 * @package LumoraFiction
 * @subpackage Update
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Update;

use ZipArchive;

/**
 * Sizes a ZIP announces are read from its index and checked first; extraction then counts the bytes it really writes
 * against the same limits. An entry that could land outside the target is refused rather than repaired, because a
 * repaired path is a path nobody reviewed.
 */
final class SafeZip
{
    public const MAX_ENTRIES = 8000;

    public const MAX_UNPACKED_BYTES = 250 * 1024 * 1024;

    public const MAX_FILE_BYTES = 40 * 1024 * 1024;

    /** Text rarely shrinks past 20 to 1; far beyond that, in a large file, is how a zip bomb looks. */
    private const MAX_RATIO = 200;

    private const RATIO_FLOOR_BYTES = 2 * 1024 * 1024;

    private const JUNK_NAMES = ['.ds_store', 'thumbs.db'];

    /**
     * @throws UpdateException when the file is missing, too big, not a ZIP or damaged
     */
    public static function open(string $path, int $maxBytes): ZipArchive
    {
        $size = is_file($path) ? filesize($path) : false;

        if ($size === false || $size === 0) {
            throw new UpdateException('The package file could not be read.');
        }

        if ($size > $maxBytes) {
            throw new UpdateException('The package is larger than ' . intdiv($maxBytes, 1048576) . ' MB, which is more than a release should need.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new UpdateException('That file is not a ZIP archive, or it is damaged. Download or upload the package again.');
        }

        return $zip;
    }

    /**
     * Every file entry, checked. Folder entries and the clutter file managers leave behind are dropped.
     *
     * @return list<array{index: int, path: string, size: int}>
     * @throws UpdateException with a plain reason when an entry is unsafe or the archive is implausibly large
     */
    public static function entries(ZipArchive $zip): array
    {
        if ($zip->numFiles < 1) {
            throw new UpdateException('That ZIP is empty.');
        }

        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new UpdateException('That ZIP holds more than ' . self::MAX_ENTRIES . ' files, which is too many for a release.');
        }

        $entries = [];
        $seen = [];
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                throw new UpdateException('That ZIP is damaged. Download or upload the package again.');
            }

            $name = (string) $stat['name'];
            $path = self::safePath($name);

            if (self::isSpecialEntry($zip, $i)) {
                throw new UpdateException('That ZIP contains a shortcut or special file, which a release cannot have.');
            }

            if (str_ends_with($name, '/') || self::isJunk($path)) {
                continue;
            }

            $key = strtolower($path);

            if (isset($seen[$key])) {
                throw new UpdateException('That ZIP lists the same file twice.');
            }

            $seen[$key] = true;
            $size = (int) $stat['size'];
            $packed = (int) $stat['comp_size'];

            if ($size > self::MAX_FILE_BYTES) {
                throw new UpdateException('One file in that ZIP is larger than ' . intdiv(self::MAX_FILE_BYTES, 1048576) . ' MB when unpacked.');
            }

            if ($size > self::RATIO_FLOOR_BYTES && $packed > 0 && intdiv($size, $packed) > self::MAX_RATIO) {
                throw new UpdateException('That ZIP unpacks to far more than its size suggests, so it was refused.');
            }

            $total += $size;

            if ($total > self::MAX_UNPACKED_BYTES) {
                throw new UpdateException('That ZIP would unpack to more than ' . intdiv(self::MAX_UNPACKED_BYTES, 1048576) . ' MB, which is too much for a release.');
            }

            $entries[] = ['index' => $i, 'path' => $path, 'size' => $size];
        }

        return $entries;
    }

    /**
     * Writes the chosen entries below the destination, counting what is really written.
     *
     * @param list<array{index: int, path: string, size: int}> $entries entries whose path is already the path to write
     * @throws UpdateException when an entry cannot be written or turns out larger than it announced
     */
    public static function extract(ZipArchive $zip, array $entries, string $destination): int
    {
        $written = 0;

        foreach ($entries as $entry) {
            $target = $destination . '/' . $entry['path'];
            $directory = dirname($target);

            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new UpdateException('The working folder cannot be written to, so the package could not be unpacked.');
            }

            $stream = $zip->getStreamIndex($entry['index']);
            $output = $stream === false ? false : fopen($target, 'wb');

            if ($stream === false || $output === false) {
                throw new UpdateException('A file in the package could not be unpacked.');
            }

            $size = 0;

            while (!feof($stream)) {
                $chunk = fread($stream, 65536);

                if ($chunk === false) {
                    break;
                }

                $size += strlen($chunk);

                if ($size > self::MAX_FILE_BYTES || $size > $entry['size'] + 1024) {
                    fclose($stream);
                    fclose($output);
                    unlink($target);

                    throw new UpdateException('A file in the package is larger than it announced, so the package was refused.');
                }

                fwrite($output, $chunk);
            }

            fclose($stream);
            fclose($output);

            $written += $size;

            if ($written > self::MAX_UNPACKED_BYTES) {
                throw new UpdateException('The package unpacked to more than it announced, so it was refused.');
            }
        }

        return $written;
    }

    /**
     * Returns the entry's path without a trailing slash, or refuses an entry that could escape or confuse.
     */
    private static function safePath(string $name): string
    {
        $trimmed = rtrim($name, '/');

        if (
            $trimmed === ''
            || strlen($name) > 240
            || preg_match('//u', $name) !== 1
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $name) === 1
            || str_starts_with($name, '/')
            || preg_match('/^[A-Za-z]:/', $name) === 1
        ) {
            throw new UpdateException('That ZIP contains a file with an unsafe name, so it was refused.');
        }

        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new UpdateException('That ZIP contains a file with an unsafe name, so it was refused.');
            }
        }

        return $trimmed;
    }

    private static function isJunk(string $path): bool
    {
        $segments = explode('/', strtolower($path));

        return in_array('__macosx', $segments, true) || in_array(end($segments), self::JUNK_NAMES, true);
    }

    /**
     * Symbolic links and device files are only recorded in the Unix permission bits of an entry.
     */
    private static function isSpecialEntry(ZipArchive $zip, int $index): bool
    {
        $system = 0;
        $attributes = 0;

        if (!$zip->getExternalAttributesIndex($index, $system, $attributes) || $system !== ZipArchive::OPSYS_UNIX) {
            return false;
        }

        $type = ($attributes >> 16) & 0xF000;

        return $type !== 0 && $type !== 0x8000 && $type !== 0x4000;
    }
}
