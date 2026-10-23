<?php

/**
 * Puts a theme into content/themes/, replaces one, or removes one, without ever leaving a half-written folder.
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
 * Files are written into a hidden staging folder beside the themes and only then renamed into place, so the
 * catalog (which ignores names that are not plain slugs) never sees a partial theme. An update keeps the old
 * folder until the new one is in place and renames it back if the swap fails.
 */
final class ThemeInstaller
{
    private const CHUNK = 65536;

    public function __construct(
        private readonly string $themesPath,
        private readonly ThemeArchive $archive,
        private readonly ThemeCatalog $catalog,
    ) {
    }

    /**
     * Whether new folders can be created in the themes folder, which decides if installing and deleting can work.
     */
    public function isWritable(): bool
    {
        return is_dir($this->themesPath) && is_writable($this->themesPath);
    }

    /**
     * @throws ThemeInstallException
     */
    public function install(string $zipPath): ThemePackage
    {
        $zip = $this->archive->open($zipPath);

        try {
            $package = $this->archive->inspect($zip);
            $destination = $this->pathFor($package->slug);

            if (file_exists($destination) || is_link($destination) || $this->catalog->find($package->slug) !== null) {
                throw new ThemeInstallException('A theme named "' . $package->slug . '" is already installed. To replace it with a newer version, use Update from its Details instead.');
            }

            $staging = $this->stage($zip, $package);

            if (!$this->quietly(static fn (): bool => rename($staging, $destination))) {
                $this->removeTree($staging);

                throw new ThemeInstallException('The theme could not be moved into place. Nothing was installed.');
            }

            return $package;
        } finally {
            $zip->close();
        }
    }

    /**
     * Replaces the files of an installed theme with those in the ZIP. The ZIP must hold the same folder name, and
     * must be newer unless the caller says an equal or older version is wanted.
     *
     * @return array{package: ThemePackage, from: string}
     *
     * @throws ThemeInstallException
     */
    public function replace(string $zipPath, string $slug, bool $allowSameOrOlder): array
    {
        $zip = $this->archive->open($zipPath);

        try {
            $package = $this->archive->inspect($zip);
            $destination = $this->pathFor($slug);
            $current = $this->catalog->find($slug);

            if (!ThemeCatalog::isValidSlug($slug) || $current === null || !is_dir($destination) || is_link($destination)) {
                throw new ThemeInstallException('That theme is not installed, so it cannot be updated.');
            }

            if ($package->slug !== $slug) {
                throw new ThemeInstallException('That ZIP holds the theme folder "' . $package->slug . '", not "' . $slug . '", so it is a different theme.');
            }

            if (!$allowSameOrOlder && version_compare($package->version, $current->version, '<=')) {
                throw new ThemeInstallException(
                    'The ZIP is version ' . $package->version . ', and version ' . $current->version . ' is installed. Tick the box to install it anyway.',
                );
            }

            $staging = $this->stage($zip, $package);
            $displaced = $this->hiddenName('replaced');

            if (!$this->quietly(static fn (): bool => rename($destination, $displaced))) {
                $this->removeTree($staging);

                throw new ThemeInstallException('The current theme files could not be moved aside, so nothing was changed.');
            }

            if (!$this->quietly(static fn (): bool => rename($staging, $destination))) {
                $restored = $this->quietly(static fn (): bool => rename($displaced, $destination));
                $this->removeTree($staging);

                throw new ThemeInstallException($restored
                    ? 'The new files could not be moved into place. The theme was left as it was.'
                    : 'The new files could not be moved into place and the old ones could not be put back. Check the themes folder.');
            }

            $this->removeTree($displaced);

            return ['package' => $package, 'from' => $current->version];
        } finally {
            $zip->close();
        }
    }

    /**
     * Removes a theme's folder. A symbolic link in its place is unlinked and never followed, and a name that is not
     * a plain slug is refused so nothing outside the themes folder can be named.
     */
    public function delete(string $slug): bool
    {
        if (!ThemeCatalog::isValidSlug($slug)) {
            return false;
        }

        $path = $this->pathFor($slug);

        if (is_link($path)) {
            return unlink($path);
        }

        $parent = realpath($this->themesPath);
        $real = realpath($path);

        if ($parent === false || $real === false || dirname($real) !== $parent || !is_dir($real)) {
            return false;
        }

        $this->removeTree($real);

        return !file_exists($real);
    }

    /**
     * Writes the package into a new hidden folder and returns its path. Anything that goes wrong removes it again.
     */
    private function stage(ZipArchive $zip, ThemePackage $package): string
    {
        $staging = $this->hiddenName('installing');

        try {
            if (!$this->quietly(static fn (): bool => mkdir($staging, 0755))) {
                throw new ThemeInstallException('The themes folder cannot be written to, so nothing was installed.');
            }

            foreach ($package->entries as $entry) {
                $target = $staging . '/' . $entry['path'];

                if ($entry['directory']) {
                    if (!is_dir($target) && !$this->quietly(static fn (): bool => mkdir($target, 0755, true))) {
                        throw new ThemeInstallException('A folder in the theme could not be created, so nothing was installed.');
                    }

                    continue;
                }

                $this->writeFile($zip, $entry, $target);
            }
        } catch (ThemeInstallException $exception) {
            $this->removeTree($staging);

            throw $exception;
        }

        return $staging;
    }

    /**
     * Copies one entry out of the ZIP, stopping if it turns out larger than the index said.
     *
     * @param array{index: int, path: string, directory: bool, size: int} $entry
     */
    private function writeFile(ZipArchive $zip, array $entry, string $target): void
    {
        $folder = dirname($target);

        if (!is_dir($folder) && !$this->quietly(static fn (): bool => mkdir($folder, 0755, true))) {
            throw new ThemeInstallException('A folder in the theme could not be created, so nothing was installed.');
        }

        $source = $zip->getStreamIndex($entry['index']);
        // Mode "x" refuses to overwrite, so a name that repeats in a different case can never replace a file.
        $output = $source === false ? false : $this->quietly(static fn (): mixed => fopen($target, 'xb'));

        if ($source === false || $output === false) {
            throw new ThemeInstallException('A file in the theme could not be unpacked, so nothing was installed.');
        }

        $written = 0;

        try {
            while (!feof($source)) {
                $chunk = fread($source, self::CHUNK);

                if ($chunk === false) {
                    throw new ThemeInstallException('A file in the theme could not be read from the ZIP, so nothing was installed.');
                }

                $written += strlen($chunk);

                if ($written > $entry['size']) {
                    throw new ThemeInstallException('A file in that ZIP is larger than the ZIP says, so it was refused.');
                }

                if ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new ThemeInstallException('The theme could not be written to disk, so nothing was installed.');
                }
            }
        } finally {
            fclose($source);
            fclose($output);
        }

        $this->quietly(static fn (): bool => chmod($target, 0644));
    }

    /**
     * Runs one filesystem call with PHP's warning for a failure muted, so the caller can turn the false it returns
     * into a message. The previous handler is always put back.
     *
     * @template T
     * @param callable(): T $call
     * @return T|false
     */
    private function quietly(callable $call): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    private function pathFor(string $slug): string
    {
        return rtrim($this->themesPath, '/') . '/' . $slug;
    }

    /**
     * A sibling name the catalog ignores, because it starts with a dot and so is never a valid slug.
     */
    private function hiddenName(string $purpose): string
    {
        return rtrim($this->themesPath, '/') . '/.' . $purpose . '-' . bin2hex(random_bytes(6));
    }

    /**
     * Deletes a folder and everything in it. Links are unlinked, not followed, so a link planted inside a theme
     * cannot make this reach outside it.
     */
    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            $this->quietly(static fn (): bool => unlink($path));

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach ($this->quietly(static fn (): array|false => scandir($path)) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->removeTree($path . '/' . $item);
            }
        }

        $this->quietly(static fn (): bool => rmdir($path));
    }
}
