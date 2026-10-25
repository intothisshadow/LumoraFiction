<?php

/**
 * Checks a release ZIP completely, without running any of it, before anything is written to the site.
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
use ParseError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * The version and requirements are read out of the package's own files with patterns, never by including them: a
 * package that has not been trusted yet must not get to run code. The PHP files are parsed (not run) once unpacked, so
 * a release written for a newer PHP than this server has, or one cut off mid-download, is refused before it replaces
 * anything.
 */
final class PackageInspector
{
    public const MAX_PACKAGE_BYTES = 128 * 1024 * 1024;

    private const TOP_LEVEL = ['app', 'admin', 'assets', 'docs', 'content', 'config', 'storage', 'install'];

    private const REQUIRED_FILES = ['index.php', 'version.php', 'app/Core/Kernel.php', 'app/Core/Requirements.php'];

    /**
     * @throws UpdateException with a plain reason when the package is not a complete Lumora Fiction release
     */
    public function inspect(string $zipPath): UpdatePackage
    {
        $zip = SafeZip::open($zipPath, self::MAX_PACKAGE_BYTES);

        try {
            return $this->describe($zip, SafeZip::entries($zip));
        } finally {
            $zip->close();
        }
    }

    /**
     * Unpacks only the files an update may write into an empty folder, then parses every PHP file in it.
     *
     * @throws UpdateException
     */
    public function extract(string $zipPath, UpdatePackage $package, string $destination): void
    {
        $zip = SafeZip::open($zipPath, self::MAX_PACKAGE_BYTES);

        try {
            $entries = array_map(
                static fn (array $file): array => ['index' => $file['index'], 'path' => $file['path'], 'size' => $file['size']],
                $package->files,
            );

            SafeZip::extract($zip, $entries, $destination);
        } finally {
            $zip->close();
        }

        $this->lint($destination);
    }

    /**
     * @throws UpdateException naming the first file that PHP cannot parse
     */
    public function lint(string $directory): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            try {
                token_get_all($source === false ? '' : $source, TOKEN_PARSE);
            } catch (ParseError) {
                $relative = ltrim(substr($file->getPathname(), strlen(rtrim($directory, '/'))), '/');

                throw new UpdateException('The package cannot be used on this server: ' . $relative . ' does not parse with PHP ' . PHP_VERSION . '. The download may be damaged, or the release may need a newer PHP.');
            }
        }
    }

    /**
     * @param list<array{index: int, path: string, size: int}> $entries
     */
    private function describe(ZipArchive $zip, array $entries): UpdatePackage
    {
        $wrapper = $this->wrapper($entries);
        $files = [];
        $ignored = 0;
        $total = 0;

        foreach ($entries as $entry) {
            $relative = $wrapper === '' ? $entry['path'] : substr($entry['path'], strlen($wrapper));

            if (!UpdateLayout::isUpdatable($relative)) {
                $ignored++;

                continue;
            }

            $files[] = ['index' => $entry['index'], 'path' => $relative, 'size' => $entry['size'], 'source' => $entry['path']];
            $total += $entry['size'];
        }

        $paths = array_column($files, 'path');

        foreach (self::REQUIRED_FILES as $required) {
            if (!in_array($required, $paths, true)) {
                throw new UpdateException('That package is not a complete Lumora Fiction release: ' . $required . ' is missing.');
            }
        }

        $migrations = [];

        foreach ($paths as $path) {
            if (preg_match('#^app/Migrations/(\d{4}_[a-z0-9_]+\.sql)\z#', $path, $match) === 1) {
                $migrations[] = $match[1];
            }
        }

        sort($migrations);

        if ($migrations === []) {
            throw new UpdateException('That package is not a complete Lumora Fiction release: it has no database migrations.');
        }

        $version = $this->versionOf($this->read($zip, $files, 'version.php'));
        $requirements = $this->requirementsOf($this->read($zip, $files, 'app/Core/Requirements.php'));

        $packages = [];

        foreach ($paths as $path) {
            $package = UpdateLayout::packageOf($path);

            if ($package !== null) {
                $packages[$package] = true;
            }
        }

        return new UpdatePackage(
            $version,
            $files,
            $total,
            $ignored,
            $migrations,
            array_keys($packages),
            $requirements['php'],
            $requirements['mysql'],
            $requirements['mariadb'],
            $requirements['extensions'],
        );
    }

    /**
     * Release ZIPs hold one folder ("LumoraFiction/") around everything; a plain archive of the site does not.
     *
     * @param list<array{index: int, path: string, size: int}> $entries
     */
    private function wrapper(array $entries): string
    {
        $first = [];

        foreach ($entries as $entry) {
            $first[explode('/', $entry['path'])[0]] = str_contains($entry['path'], '/');
        }

        if (count($first) === 1 && current($first) === true) {
            $name = (string) array_key_first($first);

            if (!in_array($name, self::TOP_LEVEL, true)) {
                return $name . '/';
            }
        }

        return '';
    }

    /**
     * @param list<array{index: int, path: string, size: int, source: string}> $files
     */
    private function read(ZipArchive $zip, array $files, string $path): string
    {
        foreach ($files as $file) {
            if ($file['path'] === $path) {
                $content = $zip->getFromIndex($file['index'], 65536);

                return $content === false ? '' : $content;
            }
        }

        return '';
    }

    private function versionOf(string $source): string
    {
        if (preg_match('/[\'"]version[\'"]\s*=>\s*[\'"](\d+\.\d+\.\d+)[\'"]/', $source, $match) !== 1) {
            throw new UpdateException('That package does not state a version number (x.y.z), so it cannot be a release.');
        }

        return $match[1];
    }

    /**
     * @return array{php: string, mysql: string, mariadb: string, extensions: list<string>}
     */
    private function requirementsOf(string $source): array
    {
        $constant = static function (string $name) use ($source): ?string {
            return preg_match('/const\s+' . $name . '\s*=\s*[\'"](\d+(?:\.\d+)*)[\'"]/', $source, $match) === 1 ? $match[1] : null;
        };

        $php = $constant('MINIMUM_PHP_VERSION');
        $mysql = $constant('MINIMUM_MYSQL');
        $mariadb = $constant('MINIMUM_MARIADB');

        if ($php === null || $mysql === null || $mariadb === null) {
            throw new UpdateException('That package does not state what it needs from the server, so it cannot be checked against this one.');
        }

        $extensions = [];

        if (preg_match('/const\s+REQUIRED_EXTENSIONS\s*=\s*\[(.*?)\]/s', $source, $list) === 1) {
            preg_match_all('/[\'"]([a-z0-9_]+)[\'"]/', $list[1], $names);
            $extensions = $names[1];
        }

        return ['php' => $php, 'mysql' => $mysql, 'mariadb' => $mariadb, 'extensions' => $extensions];
    }
}
