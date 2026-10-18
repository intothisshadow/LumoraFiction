<?php

/**
 * What the stylesheet header of an installed theme says about it.
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

final readonly class ThemeInfo
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $version,
        public string $author,
        public string $description,
        public string $path,
        public bool $builtIn = false,
    ) {
    }
}
