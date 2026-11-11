<?php

/**
 * The pictures a theme can draw from a folder an administrator controls (added by FTP) and the store for
 * pictures uploaded through a theme option.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Http\BasePath;
use LumoraFiction\Core\Media\ThemePictureStore;

/**
 * The folder lives under the uploads directory, not in a theme, so a theme update never removes the pictures. Only
 * files named banner-{words}.{jpg|jpeg|png|webp|svg} are offered, and each is checked on every request: a raster
 * file must really be that kind of picture, and an SVG must carry nothing that runs or loads something else.
 */
final class ThemePictures
{
    private const NAME_PATTERN = '/^banner-([a-z0-9][a-z0-9-]{0,59})\.(jpe?g|png|webp|svg)\z/';

    private const MAX_RASTER_BYTES = 3145728;

    private const MAX_SVG_BYTES = 262144;

    /** Anything in an SVG that can run script, pull in another file or expand into something large. */
    private const SVG_FORBIDDEN = [
        '/<\s*(?:script|foreignObject|iframe|object|embed)\b/i',
        '/\son[a-z]+\s*=/i',
        '/javascript\s*:/i',
        '/<!ENTITY/i',
        '/@import/i',
        '/(?:href|src)\s*=\s*["\']\s*(?!#|data:image\/)/i',
        '/url\(\s*["\']?\s*(?!#|data:image\/)/i',
    ];

    /** @var array<string, string>|null file name => label, read once per request */
    private ?array $listed = null;

    public function __construct(
        private readonly string $folder,
        private readonly string $urlPath,
        private readonly ThemePictureStore $uploads,
    ) {
    }

    /**
     * The usable pictures in the folder, in name order.
     *
     * @return array<string, string> file name => a label made from the words in the name
     */
    public function folder(): array
    {
        if ($this->listed !== null) {
            return $this->listed;
        }

        $found = [];
        $names = is_dir($this->folder) ? scandir($this->folder) : false;

        foreach ($names === false ? [] : $names as $name) {
            $path = $this->folder . DIRECTORY_SEPARATOR . $name;

            if (preg_match(self::NAME_PATTERN, $name, $match) !== 1 || is_link($path) || !is_file($path) || !$this->usable($path, $match[2])) {
                continue;
            }

            $found[$name] = ucfirst(str_replace('-', ' ', $match[1]));
        }

        ksort($found, SORT_NATURAL);

        return $this->listed = $found;
    }

    public function hasFolderPicture(string $name): bool
    {
        return isset($this->folder()[$name]);
    }

    /**
     * The address of a folder picture, or an empty string for a name that is not one of the usable pictures.
     */
    public function folderUrl(string $name): string
    {
        return $this->hasFolderPicture($name) ? BasePath::get() . '/' . trim($this->urlPath, '/') . '/' . $name : '';
    }

    public function uploads(): ThemePictureStore
    {
        return $this->uploads;
    }

    private function usable(string $path, string $extension): bool
    {
        $size = filesize($path);

        if ($size === false || $size === 0) {
            return false;
        }

        if ($extension === 'svg') {
            return $size <= self::MAX_SVG_BYTES && $this->safeSvg($path);
        }

        if ($size > self::MAX_RASTER_BYTES) {
            return false;
        }

        // The header is what decides, so a script renamed banner-x.jpg is not offered.
        $info = getimagesize($path);

        return $info !== false && $info[2] === match ($extension) {
            'png' => IMAGETYPE_PNG,
            'webp' => IMAGETYPE_WEBP,
            default => IMAGETYPE_JPEG,
        };
    }

    private function safeSvg(string $path): bool
    {
        $text = file_get_contents($path);

        if ($text === false || preg_match('/<svg\b/i', $text) !== 1) {
            return false;
        }

        foreach (self::SVG_FORBIDDEN as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return false;
            }
        }

        return true;
    }
}
