<?php

/**
 * Opens an uploaded theme ZIP and checks it completely before anything is written to disk.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Theme;

use ZipArchive;

/**
 * The sizes a ZIP claims are read from its index, so they are limits on what is announced, and extraction counts
 * the bytes it really writes against the same numbers. Anything that could place a file outside the theme folder
 * is refused outright instead of being cleaned up, because a repaired path is a path nobody reviewed.
 */
final class ThemeArchive
{
    public const MAX_ARCHIVE_BYTES = 20 * 1024 * 1024;

    public const MAX_ENTRIES = 1500;

    public const MAX_UNPACKED_BYTES = 30 * 1024 * 1024;

    public const MAX_FILE_BYTES = 10 * 1024 * 1024;

    /** A text file rarely shrinks past 20 to 1; far beyond that, in a large file, is how a zip bomb looks. */
    private const MAX_RATIO = 100;

    private const RATIO_FLOOR_BYTES = 1024 * 1024;

    private const HEADER_BYTES = 8192;

    /** Server configuration files would let an upload change how the web server treats the theme folder. */
    private const FORBIDDEN_NAMES = ['.htaccess', '.user.ini', 'php.ini', 'web.config'];

    /** Left behind by file managers; harmless, so they are skipped rather than counted as a second folder. */
    private const JUNK_NAMES = ['.ds_store', 'thumbs.db'];

    public function __construct(
        private readonly string $appVersion,
        private readonly string $phpVersion = PHP_VERSION,
    ) {
    }

    /**
     * @throws ThemeInstallException when the file is missing, too big, not a ZIP or damaged
     */
    public function open(string $path): ZipArchive
    {
        $size = is_file($path) ? filesize($path) : false;

        if ($size === false || $size === 0) {
            throw new ThemeInstallException('The uploaded file could not be read.');
        }

        if ($size > self::MAX_ARCHIVE_BYTES) {
            throw new ThemeInstallException('That ZIP is larger than ' . (self::MAX_ARCHIVE_BYTES / 1048576) . ' MB, which is more than a theme should need.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ThemeInstallException('That file is not a ZIP archive, or it is damaged. Pack the theme folder again and upload the new file.');
        }

        return $zip;
    }

    /**
     * @throws ThemeInstallException with a plain reason when the archive is not one theme folder that can be installed
     */
    public function inspect(ZipArchive $zip): ThemePackage
    {
        $count = $zip->numFiles;

        if ($count < 1) {
            throw new ThemeInstallException('That ZIP is empty.');
        }

        if ($count > self::MAX_ENTRIES) {
            throw new ThemeInstallException('That ZIP holds more than ' . self::MAX_ENTRIES . ' files, which is too many for a theme.');
        }

        $entries = [];
        $seen = [];
        $total = 0;

        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                throw new ThemeInstallException('That ZIP is damaged. Pack the theme folder again and upload the new file.');
            }

            $name = (string) $stat['name'];
            $directory = str_ends_with($name, '/');
            $path = $this->safePath($name);

            if ($this->isSpecialEntry($zip, $i)) {
                throw new ThemeInstallException('That ZIP contains a shortcut or special file, which a theme cannot have.');
            }

            if ($this->isJunk($path)) {
                continue;
            }

            $key = strtolower($path);

            if (isset($seen[$key])) {
                throw new ThemeInstallException('That ZIP lists the same file twice.');
            }

            $seen[$key] = true;
            $size = $directory ? 0 : (int) $stat['size'];
            $packed = (int) $stat['comp_size'];

            if ($size > self::MAX_FILE_BYTES) {
                throw new ThemeInstallException('One file in that ZIP is larger than ' . (self::MAX_FILE_BYTES / 1048576) . ' MB when unpacked.');
            }

            if ($size > self::RATIO_FLOOR_BYTES && $packed > 0 && intdiv($size, $packed) > self::MAX_RATIO) {
                throw new ThemeInstallException('That ZIP unpacks to far more than its size suggests, so it was refused.');
            }

            $total += $size;

            if ($total > self::MAX_UNPACKED_BYTES) {
                throw new ThemeInstallException('That ZIP would unpack to more than ' . (self::MAX_UNPACKED_BYTES / 1048576) . ' MB, which is too much for a theme.');
            }

            $entries[] = ['index' => $i, 'path' => $path, 'directory' => $directory, 'size' => $size];
        }

        return $this->describe($zip, $entries, $total);
    }

    /**
     * @param list<array{index: int, path: string, directory: bool, size: int}> $entries
     */
    private function describe(ZipArchive $zip, array $entries, int $total): ThemePackage
    {
        $roots = [];

        foreach ($entries as $entry) {
            $segments = explode('/', $entry['path']);

            if (count($segments) === 1 && !$entry['directory']) {
                throw new ThemeInstallException('Every file in the ZIP must be inside one theme folder, but some sit outside it.');
            }

            $roots[$segments[0]] = true;
        }

        if ($roots === []) {
            throw new ThemeInstallException('That ZIP is empty.');
        }

        if (count($roots) > 1) {
            throw new ThemeInstallException('That ZIP must hold exactly one theme folder, but it holds ' . count($roots) . '.');
        }

        $slug = (string) array_key_first($roots);

        if (!ThemeCatalog::isValidSlug($slug)) {
            throw new ThemeInstallException('The theme folder in the ZIP is named "' . $this->shown($slug) . '". A theme folder name uses lowercase letters, numbers and hyphens, and starts with a letter.');
        }

        $inside = [];
        $byPath = [];

        foreach ($entries as $entry) {
            $relative = substr($entry['path'], strlen($slug) + 1);

            if ($relative === '' || $relative === false) {
                continue;
            }

            if (in_array(strtolower(basename($relative)), self::FORBIDDEN_NAMES, true)) {
                throw new ThemeInstallException('That ZIP contains a server settings file (' . basename($relative) . '), which a theme cannot include.');
            }

            $entry['path'] = $relative;
            $inside[] = $entry;

            if (!$entry['directory']) {
                $byPath[$relative] = $entry;
            }
        }

        foreach (['index.php', 'style.css'] as $required) {
            if (!isset($byPath[$required])) {
                throw new ThemeInstallException('The theme folder has no ' . $required . ' file, so it is not a complete theme.');
            }
        }

        $css = $zip->getFromIndex($byPath['style.css']['index'], self::HEADER_BYTES);
        $header = $css === false ? null : ThemeCatalog::parseHeader($css);

        if ($header === null) {
            throw new ThemeInstallException('style.css has no usable header. It must start with a comment that gives a "Version:" such as 1.0.0.');
        }

        $name = $header['name'] !== '' ? $header['name'] : $slug;

        $problems = (new ThemeInfo($slug, $name, $header['version'], '', '', '', false, $header['requires_php'], $header['requires_app']))
            ->unmetRequirements($this->phpVersion, $this->appVersion);

        if ($problems !== []) {
            throw new ThemeInstallException('"' . $name . '" cannot be used on this site. ' . implode(' ', $problems));
        }

        return new ThemePackage($slug, $name, $header['version'], $inside, $total);
    }

    /**
     * Returns the entry's path without its trailing slash, or refuses an entry that could escape or confuse.
     */
    private function safePath(string $name): string
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
            throw new ThemeInstallException('That ZIP contains a file with an unsafe name, so it was refused.');
        }

        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new ThemeInstallException('That ZIP contains a file with an unsafe name, so it was refused.');
            }
        }

        return $trimmed;
    }

    private function isJunk(string $path): bool
    {
        $segments = explode('/', strtolower($path));

        return $segments[0] === '__macosx' || in_array(end($segments), self::JUNK_NAMES, true);
    }

    /**
     * Symbolic links and device files are only recorded in the Unix permission bits of an entry.
     */
    private function isSpecialEntry(ZipArchive $zip, int $index): bool
    {
        $system = 0;
        $attributes = 0;

        if (!$zip->getExternalAttributesIndex($index, $system, $attributes) || $system !== ZipArchive::OPSYS_UNIX) {
            return false;
        }

        $type = ($attributes >> 16) & 0xF000;

        return $type !== 0 && $type !== 0x8000 && $type !== 0x4000;
    }

    /**
     * A folder name quoted back to the administrator, with anything unprintable removed.
     */
    private function shown(string $value): string
    {
        return mb_substr((string) preg_replace('/[^\p{L}\p{N}._ -]+/u', '?', $value), 0, 50);
    }
}
