<?php

/**
 * Finds the themes installed under content/themes/ and reads their metadata without running any of their code.
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

/**
 * A folder counts as a theme only when its name is a plain slug and it holds a stylesheet with a valid header and
 * an index template. Anything else is ignored rather than reported, so a half-copied upload never appears as a
 * choice. The built-in theme is always available and cannot be shadowed by a folder of the same name.
 */
final class ThemeCatalog
{
    public const FALLBACK_SLUG = 'fallback';

    /** The header sits at the top of the stylesheet; reading more than this would only be reading CSS. */
    private const HEADER_BYTES = 8192;

    public function __construct(
        private readonly string $themesPath,
        private readonly string $fallbackPath,
    ) {
    }

    public function fallback(): ThemeInfo
    {
        return new ThemeInfo(
            self::FALLBACK_SLUG,
            'Built-in theme',
            '1.0',
            'Lumora Fiction',
            'A plain theme that keeps the site readable when no other theme is available.',
            rtrim($this->fallbackPath, '/'),
            true,
        );
    }

    /**
     * The valid installed themes, by name.
     *
     * @return array<string, ThemeInfo> keyed by slug
     */
    public function installed(): array
    {
        $themes = [];

        foreach (is_dir($this->themesPath) ? (scandir($this->themesPath) ?: []) : [] as $entry) {
            $theme = $this->load($entry);

            if ($theme !== null) {
                $themes[$theme->slug] = $theme;
            }
        }

        uasort($themes, static fn (ThemeInfo $a, ThemeInfo $b): int => strcasecmp($a->name, $b->name));

        return $themes;
    }

    public function find(string $slug): ?ThemeInfo
    {
        return $slug === self::FALLBACK_SLUG ? $this->fallback() : $this->load($slug);
    }

    /**
     * Reads the comment at the top of a stylesheet. Returns null when there is no usable header: a name and a
     * version are both required, and a version must look like one.
     *
     * @return array{name: string, version: string, author: string, description: string}|null
     */
    public static function parseHeader(string $css): ?array
    {
        $css = ltrim(str_starts_with($css, "\xEF\xBB\xBF") ? substr($css, 3) : $css);

        if (!str_starts_with($css, '/*')) {
            return null;
        }

        $end = strpos($css, '*/');
        $comment = $end === false ? substr($css, 2) : substr($css, 2, $end - 2);
        $fields = [];

        foreach (preg_split('/\R/', $comment) ?: [] as $line) {
            if (preg_match('/^[\s*]*(Theme Name|Version|Author|Description)\s*:\s*(.*?)\s*\z/i', $line, $match) === 1) {
                $fields[strtolower($match[1])] ??= self::clean($match[2]);
            }
        }

        $name = $fields['theme name'] ?? '';
        $version = $fields['version'] ?? '';

        if ($name === '' || preg_match('/^[0-9]+(\.[0-9]+){0,3}(-[A-Za-z0-9.]+)?\z/', $version) !== 1) {
            return null;
        }

        return [
            'name' => mb_substr($name, 0, 80),
            'version' => $version,
            'author' => mb_substr($fields['author'] ?? '', 0, 80),
            'description' => mb_substr($fields['description'] ?? '', 0, 300),
        ];
    }

    private function load(string $slug): ?ThemeInfo
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,49}\z/', $slug) !== 1 || $slug === self::FALLBACK_SLUG) {
            return null;
        }

        $path = rtrim($this->themesPath, '/') . '/' . $slug;

        if (!is_dir($path) || !is_file($path . '/index.php') || !is_file($path . '/style.css')) {
            return null;
        }

        $css = file_get_contents($path . '/style.css', false, null, 0, self::HEADER_BYTES);
        $header = $css === false ? null : self::parseHeader($css);

        return $header === null ? null : new ThemeInfo($slug, $header['name'], $header['version'], $header['author'], $header['description'], $path);
    }

    /**
     * Header values are shown on the Appearance screen and escaped there; control characters are removed here
     * so they cannot be used to break a log line or a table.
     */
    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value));
    }
}
