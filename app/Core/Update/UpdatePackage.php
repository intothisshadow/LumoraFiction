<?php

/**
 * What an inspected release package contains and needs.
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

final class UpdatePackage
{
    /**
     * @param list<array{index: int, path: string, size: int, source: string}> $files the files an update may write, with the path to write and the name inside the ZIP
     * @param list<string> $migrations migration file names the package ships
     * @param list<string> $packages bundled plugin and theme folders ("content/plugins/slug") the package ships
     * @param list<string> $extensions PHP extensions the release needs
     */
    public function __construct(
        public readonly string $version,
        public readonly array $files,
        public readonly int $unpackedBytes,
        public readonly int $ignoredFiles,
        public readonly array $migrations,
        public readonly array $packages,
        public readonly string $minimumPhp,
        public readonly string $minimumMysql,
        public readonly string $minimumMariadb,
        public readonly array $extensions,
    ) {
    }
}
