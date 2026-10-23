<?php

/**
 * Finds and vets the preview picture a theme ships for the Appearance screen.
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
 * The picture is read from the theme folder by the admin screen and sent with a type worked out from its bytes,
 * never from its name, so a file that only claims to be an image is ignored instead of being served.
 */
final class ThemeScreenshot
{
    public const MAX_BYTES = 1024 * 1024;

    private const MIN_SIDE = 100;

    private const MAX_SIDE = 4000;

    /** File name => the image type its bytes must have. */
    private const CANDIDATES = [
        'screenshot.webp' => IMAGETYPE_WEBP,
        'screenshot.jpg' => IMAGETYPE_JPEG,
        'screenshot.png' => IMAGETYPE_PNG,
    ];

    private const MIME = [
        IMAGETYPE_WEBP => 'image/webp',
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
    ];

    /**
     * @return array{path: string, mime: string}|null null when the theme has no usable picture
     */
    public function locate(ThemeInfo $theme): ?array
    {
        foreach (self::CANDIDATES as $file => $type) {
            $path = rtrim($theme->path, '/') . '/' . $file;

            // A link could point at any file the web server can read, so only a real file is considered.
            if (is_link($path) || !is_file($path)) {
                continue;
            }

            $size = filesize($path);

            if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
                continue;
            }

            // A damaged picture makes getimagesize() warn; that only means "not usable here".
            set_error_handler(static fn (): bool => true);

            try {
                $info = getimagesize($path);
            } finally {
                restore_error_handler();
            }

            if (
                $info === false
                || $info[2] !== $type
                || $info[0] < self::MIN_SIDE || $info[1] < self::MIN_SIDE
                || $info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE
            ) {
                continue;
            }

            return ['path' => $path, 'mime' => self::MIME[$type]];
        }

        return null;
    }
}
