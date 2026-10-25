<?php

/**
 * Copies a prepared folder of application files over the site, and removes what the new folder no longer ships.
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

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The same operation applies a release and rolls one back: both are "make these application files look like that
 * folder". Every path is checked against UpdateLayout, and a folder that is a symbolic link is refused instead of
 * followed, so a link planted or configured to point elsewhere can never make an update write outside the site.
 * Running it twice does the same as running it once, which is what lets an interrupted update be resumed.
 */
final class FileSync
{
    public function __construct(private readonly string $siteRoot)
    {
    }

    /**
     * @param list<string> $skipPackages package folders ("content/plugins/slug") that must be left as they are
     * @return array{written: int, removed: int, skipped: list<string>}
     * @throws UpdateException when a file cannot be written or a destination is a symbolic link
     */
    public function apply(string $source, array $skipPackages = []): array
    {
        $files = $this->sourceFiles($source, $skipPackages);
        $written = 0;
        $skipped = [];

        // A package folder is only reported once, however many files it holds.
        foreach ($this->sourcePackages($source) as $package) {
            if (in_array($package, $skipPackages, true)) {
                $skipped[] = $package;
            }
        }

        foreach ($files as $relative) {
            if ($this->copyFile($source . '/' . $relative, $relative)) {
                $written++;
            }
        }

        return ['written' => $written, 'removed' => $this->removeLeftovers($files), 'skipped' => $skipped];
    }

    /**
     * Fails before anything is written when the web server could not write what apply() would write. A run that finds
     * this out only on its first file would already count as having changed the site, with nothing to roll back to.
     *
     * @param list<string> $skipPackages
     * @throws UpdateException naming the first folders that cannot be written
     */
    public function assertWritable(string $source, array $skipPackages = []): void
    {
        $this->assertPathsWritable($this->sourceFiles($source, $skipPackages));
    }

    /**
     * @param list<string> $relativePaths files that are about to be written, relative to the site
     * @throws UpdateException naming the first folders that cannot be written
     */
    public function assertPathsWritable(array $relativePaths): void
    {
        $blocked = [];

        foreach ($relativePaths as $relative) {
            $segments = explode('/', $relative);
            array_pop($segments);

            // Files are replaced by writing a new one beside them and renaming, so what must be writable is the folder,
            // or the nearest folder that exists when the file's own is yet to be made.
            while ($segments !== [] && !is_dir($this->siteRoot . '/' . implode('/', $segments))) {
                array_pop($segments);
            }

            $directory = implode('/', $segments);

            if (!is_writable($this->siteRoot . ($directory === '' ? '' : '/' . $directory))) {
                $blocked[$directory === '' ? 'the site folder' : $directory] = true;
            }
        }

        if ($blocked !== []) {
            $names = array_slice(array_keys($blocked), 0, 5);

            throw new UpdateException('The web server cannot write to ' . implode(', ', $names) . (count($blocked) > 5 ? ' and ' . (count($blocked) - 5) . ' more folders' : '') . ', so nothing was changed. Change these permissions (or ask the host to), then resume.');
        }
    }

    /**
     * @param list<string> $skipPackages
     * @return list<string> relative paths of the files to install, sorted
     */
    private function sourceFiles(string $source, array $skipPackages): array
    {
        $files = [];
        $length = strlen(rtrim($source, '/')) + 1;

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), $length));

            if (!UpdateLayout::isUpdatable($relative)) {
                continue;
            }

            $package = UpdateLayout::packageOf($relative);

            if ($package !== null && in_array($package, $skipPackages, true)) {
                continue;
            }

            $files[] = $relative;
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function sourcePackages(string $source): array
    {
        $packages = [];

        foreach (UpdateLayout::PACKAGE_PARENTS as $parent) {
            foreach (glob($source . '/' . $parent . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
                $packages[] = $parent . '/' . basename($directory);
            }
        }

        return $packages;
    }

    /**
     * @throws UpdateException
     */
    private function copyFile(string $from, string $relative): bool
    {
        $to = $this->siteRoot . '/' . $relative;

        $this->assertNoLinks($relative);

        if (is_file($to) && filesize($to) === filesize($from) && hash_file('sha256', $to) === hash_file('sha256', $from)) {
            return false;
        }

        $directory = dirname($to);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new UpdateException('The folder for ' . $relative . ' cannot be created. Check that the web server may write to the site folders.');
        }

        // Replaced by a rename, so a request that dies halfway never leaves a half-written file behind.
        $temporary = $to . '.lf-new';

        if (!copy($from, $temporary) || !rename($temporary, $to)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw new UpdateException($relative . ' cannot be written. Check that the web server may write to the site folders.');
        }

        return true;
    }

    /**
     * @param list<string> $installed relative paths just installed (and so to be kept)
     */
    private function removeLeftovers(array $installed): int
    {
        $keep = array_flip($installed);
        $roots = [];

        foreach ($installed as $relative) {
            $root = UpdateLayout::mirroredRootOf($relative);

            if ($root !== null) {
                $roots[$root] = true;
            }
        }

        $removed = 0;

        foreach (array_keys($roots) as $root) {
            $directory = $this->siteRoot . '/' . $root;

            if (!is_dir($directory)) {
                continue;
            }

            $this->assertNoLinks($root . '/x');

            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($items as $item) {
                $relative = $root . '/' . str_replace('\\', '/', substr($item->getPathname(), strlen($directory) + 1));

                if ($item->isDir() && !$item->isLink()) {
                    // Only folders that ended up empty are removed, and never one that still holds a file.
                    if (count(scandir($item->getPathname()) ?: []) <= 2) {
                        rmdir($item->getPathname());
                    }

                    continue;
                }

                if (!isset($keep[$relative]) && UpdateLayout::isUpdatable($relative)) {
                    unlink($item->getPathname());
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * @throws UpdateException when a folder on the way to a file is a symbolic link
     */
    private function assertNoLinks(string $relative): void
    {
        $segments = explode('/', $relative);
        array_pop($segments);
        $path = $this->siteRoot;
        $walked = [];

        foreach ($segments as $segment) {
            $path .= '/' . $segment;
            $walked[] = $segment;

            if (is_link($path)) {
                throw new UpdateException('The folder ' . implode('/', $walked) . ' is a shortcut to somewhere else, so the update will not write into it. Replace it with a real folder first.');
            }

            if (!file_exists($path)) {
                return;
            }
        }
    }
}
