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
    /**
     * @param string $requiresPhp the lowest PHP version the theme works on, or an empty string
     * @param string $requiresApp the lowest application version the theme works with, or an empty string
     * @param bool $bundled whether the theme ships with Lumora Fiction, so an update brings it back
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $version,
        public string $author,
        public string $description,
        public string $path,
        public bool $builtIn = false,
        public string $requiresPhp = '',
        public string $requiresApp = '',
        public bool $bundled = false,
    ) {
    }

    /**
     * Plain sentences for each requirement this site does not meet; empty when the theme can be used here.
     *
     * @return list<string>
     */
    public function unmetRequirements(string $phpVersion, string $appVersion): array
    {
        $problems = [];

        if ($this->requiresPhp !== '' && version_compare($phpVersion, $this->requiresPhp, '<')) {
            $problems[] = sprintf('Needs PHP %s or newer; this server runs PHP %s.', $this->requiresPhp, $phpVersion);
        }

        if ($this->requiresApp !== '' && version_compare($appVersion, $this->requiresApp, '<')) {
            $problems[] = sprintf('Needs Lumora Fiction %s or newer; this site runs %s.', $this->requiresApp, $appVersion);
        }

        return $problems;
    }
}
