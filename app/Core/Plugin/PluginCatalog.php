<?php

/**
 * Finds the plugins installed under content/plugins/ and reads their headers without running any of their code.
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

/**
 * A plugin is the folder content/plugins/{slug}/ holding {slug}.php, whose header comment names it. The slug comes
 * from the folder name and is checked before it is ever used in a path, and the main file's real path must stay
 * inside the plugin's own folder, so a link or a hand-edited setting cannot point the loader at another file.
 */
final class PluginCatalog
{
    public const SLUG_PATTERN = '/^[a-z][a-z0-9-]{0,49}\z/';

    /** The header sits at the top of the main file; reading more than this would only be reading code. */
    private const HEADER_BYTES = 8192;

    public function __construct(private readonly string $pluginsPath)
    {
    }

    public static function isValidSlug(string $slug): bool
    {
        return preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    /**
     * The valid installed plugins, by name.
     *
     * @return array<string, PluginInfo> keyed by slug
     */
    public function installed(): array
    {
        $plugins = [];

        foreach (is_dir($this->pluginsPath) ? (scandir($this->pluginsPath) ?: []) : [] as $entry) {
            $plugin = $this->find($entry);

            if ($plugin !== null) {
                $plugins[$plugin->slug] = $plugin;
            }
        }

        uasort($plugins, static fn (PluginInfo $a, PluginInfo $b): int => strcasecmp($a->name, $b->name));

        return $plugins;
    }

    public function find(string $slug): ?PluginInfo
    {
        if (!self::isValidSlug($slug)) {
            return null;
        }

        $root = realpath($this->pluginsPath);
        $folder = realpath(rtrim($this->pluginsPath, '/') . '/' . $slug);
        $main = $folder === false ? false : realpath($folder . '/' . $slug . '.php');

        if ($root === false || $folder === false || $main === false) {
            return null;
        }

        if (!str_starts_with($folder, $root . DIRECTORY_SEPARATOR) || !str_starts_with($main, $folder . DIRECTORY_SEPARATOR) || !is_file($main)) {
            return null;
        }

        $source = file_get_contents($main, false, null, 0, self::HEADER_BYTES);
        $header = $source === false ? null : self::parseHeader($source);

        if ($header === null) {
            return null;
        }

        return new PluginInfo($slug, $header['name'], $header['version'], $header['author'], $header['description'], $main, $header['requires_php'], $header['requires_app']);
    }

    /**
     * Reads the first comment block that holds a "Plugin Name:" line. A name and a version are both required, and a
     * version must look like one. The text is only read, never run.
     *
     * @return array{name: string, version: string, author: string, description: string, requires_php: string, requires_app: string}|null
     */
    public static function parseHeader(string $source): ?array
    {
        if (preg_match_all('#/\*.*?\*/#s', $source, $blocks) === 0) {
            return null;
        }

        foreach ($blocks[0] as $block) {
            $fields = [];

            foreach (preg_split('/\R/', $block) ?: [] as $line) {
                if (preg_match('/^[\s\/*]*(Plugin Name|Version|Author|Description|Requires PHP|Requires at least)\s*:\s*(.*?)\s*(\*\/)?\s*\z/i', $line, $match) === 1) {
                    $fields[strtolower($match[1])] ??= self::clean($match[2]);
                }
            }

            $name = $fields['plugin name'] ?? '';
            $version = $fields['version'] ?? '';
            $requiresPhp = $fields['requires php'] ?? '';
            $requiresApp = $fields['requires at least'] ?? '';

            if ($name === '') {
                continue;
            }

            if (
                preg_match('/^[0-9]+(\.[0-9]+){0,3}(-[A-Za-z0-9.]+)?\z/', $version) !== 1
                || ($requiresPhp !== '' && preg_match('/^[0-9]+(\.[0-9]+){0,2}\z/', $requiresPhp) !== 1)
                || ($requiresApp !== '' && preg_match('/^[0-9]+(\.[0-9]+){0,2}\z/', $requiresApp) !== 1)
            ) {
                return null;
            }

            return [
                'name' => mb_substr($name, 0, 80),
                'version' => $version,
                'author' => mb_substr($fields['author'] ?? '', 0, 80),
                'description' => mb_substr($fields['description'] ?? '', 0, 300),
                'requires_php' => $requiresPhp,
                'requires_app' => $requiresApp,
            ];
        }

        return null;
    }

    /**
     * Header values are shown on the Plugins screen and escaped there; control characters are removed here
     * so they cannot be used to break a log line or a table.
     */
    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value));
    }
}
