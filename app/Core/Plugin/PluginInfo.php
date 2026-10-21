<?php

/**
 * What the header of an installed plugin says about it.
 *
 * @package LumoraFiction
 * @subpackage Plugin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Plugin;

final readonly class PluginInfo
{
    /**
     * @param string $mainFile the real path of the one file the loader may include
     * @param string $requiresPhp the lowest PHP version the plugin works on, or an empty string
     * @param string $requiresApp the lowest application version the plugin works with, or an empty string
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $version,
        public string $author,
        public string $description,
        public string $mainFile,
        public string $requiresPhp = '',
        public string $requiresApp = '',
    ) {
    }

    /**
     * Plain-language reasons this plugin cannot run here, empty when it can.
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
