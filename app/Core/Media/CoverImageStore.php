<?php

/**
 * Turns an uploaded picture into a story cover: checks it is really a JPEG, PNG or WebP, re-encodes it at two
 * sizes under a generated name, and removes those files again.
 *
 * @package LumoraFiction
 * @subpackage Media
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Media;

use GdImage;
use LumoraFiction\Core\Http\BasePath;

/**
 * Nothing the visitor sent is ever kept: the picture is decoded and drawn again from its pixels, so a payload
 * hidden in the file, a second format glued on the end and every metadata block (including the location in a
 * photo's EXIF) are gone. The name is random and carries no part of the original, so it cannot be guessed or
 * used to reach another path.
 */
final class CoverImageStore
{
    /** The largest file accepted, before the server's own upload limit is taken into account. */
    public const MAX_BYTES = 2097152;

    /** Decoding needs memory in proportion to the pixel count, so a huge picture is refused before it is decoded. */
    public const MAX_PIXELS = 12000000;

    public const MIN_SIDE = 100;

    /** Largest box each size is scaled to fit; a smaller picture is never enlarged. */
    public const FULL = [600, 900];

    public const THUMB = [200, 300];

    private const TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];

    private const NAME_PATTERN = '/^[0-9a-f]{32}\.(?:jpg|png|webp)\z/';

    public function __construct(
        private readonly string $directory,
        private readonly string $urlPath = 'content/uploads/covers',
    ) {
    }

    /**
     * The largest upload this server will take, which is the smaller of ours and PHP's own limit.
     */
    public static function limitBytes(): int
    {
        $php = self::iniBytes((string) ini_get('upload_max_filesize'));

        return $php > 0 ? min(self::MAX_BYTES, $php) : self::MAX_BYTES;
    }

    /**
     * The file name the database may hold, or false for anything else; checked again on every use so a damaged
     * row can never point outside the covers folder.
     */
    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Whether this server can handle covers at all.
     */
    public static function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring') && function_exists('imagejpeg');
    }

    /**
     * Reads the crop a form sent: four fractions of the picture as it is shown (upright), or null when none was
     * chosen or the numbers make no sense. A bad crop is ignored rather than guessed at, so the whole picture is used.
     *
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    public static function parseCrop(?string $x, ?string $y, ?string $w, ?string $h): ?array
    {
        $values = [];

        foreach (['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h] as $key => $text) {
            if ($text === null || preg_match('/^\d(?:\.\d{1,10})?\z/', $text) !== 1) {
                return null;
            }

            $values[$key] = (float) $text;
        }

        if ($values['w'] <= 0.0 || $values['h'] <= 0.0 || $values['x'] + $values['w'] > 1.000001 || $values['y'] + $values['h'] > 1.000001) {
            return null;
        }

        return $values;
    }

    /**
     * @param array{x: float, y: float, w: float, h: float}|null $crop the part of the picture to keep, as fractions
     * @return string the name of the full-size file; the thumbnail has the same name with "-thumb" before the dot
     * @throws CoverImageException when the file is not an acceptable picture
     */
    public function save(string $sourcePath, ?array $crop = null): string
    {
        if (!self::isAvailable()) {
            throw new CoverImageException('This server cannot process pictures, so covers are not available.');
        }

        $size = is_file($sourcePath) && is_readable($sourcePath) ? filesize($sourcePath) : false;

        if ($size === false || $size === 0) {
            throw new CoverImageException('That file could not be read.');
        }

        if ($size > self::limitBytes()) {
            throw new CoverImageException('That picture is too large. Keep it under ' . self::megabytes(self::limitBytes()) . ' MB.');
        }

        // getimagesize() reads the real header, so a renamed script or a wrong Content-Type is not mistaken for a picture.
        $info = getimagesize($sourcePath);

        if ($info === false || !isset(self::TYPES[$info[2]])) {
            throw new CoverImageException('Use a JPEG, PNG or WebP picture.');
        }

        [$width, $height] = $info;
        $type = $info[2];

        if ($width < self::MIN_SIDE || $height < self::MIN_SIDE) {
            throw new CoverImageException('That picture is too small. Use one at least ' . self::MIN_SIDE . ' pixels wide and tall.');
        }

        if ($width * $height > self::MAX_PIXELS) {
            throw new CoverImageException('That picture has too many pixels. Reduce it to about 3000 by 4000 or smaller.');
        }

        if ($type === IMAGETYPE_WEBP && !function_exists('imagecreatefromwebp')) {
            throw new CoverImageException('This server cannot read WebP pictures. Use a JPEG or PNG.');
        }

        $bytes = file_get_contents($sourcePath);
        // GD reports a damaged file as a warning, which the error handler would turn into a failure page.
        $image = $bytes === false ? false : $this->decode($bytes);

        if (!$image instanceof GdImage) {
            throw new CoverImageException('That picture could not be read. It may be damaged.');
        }

        $image = $this->upright($image, $type, $sourcePath);

        if ($crop !== null) {
            $image = $this->cropped($image, $crop);
        }

        $name = bin2hex(random_bytes(16)) . '.' . self::TYPES[$type];
        $written = [];

        // A full disk or a folder the server cannot write to is reported by PHP as a warning; it is turned into
        // the same exception as every other failure, so one cleanup path covers them all.
        set_error_handler(static function (int $level, string $message): never {
            throw new CoverImageException('The picture could not be saved. Please try again.');
        });

        try {
            $this->ensureDirectory();

            foreach (['' => self::FULL, '-thumb' => self::THUMB] as $suffix => $box) {
                $path = $this->path($this->variantName($name, $suffix));
                $written[] = $path;
                $this->write($this->scaled($image, $box), $type, $path);
            }
        } catch (CoverImageException $exception) {
            restore_error_handler();

            foreach ($written as $path) {
                $this->remove($path);
            }

            throw $exception;
        }

        restore_error_handler();

        return $name;
    }

    /**
     * Removes both files of a cover. A name that is not one this class made is ignored.
     */
    public function delete(string $name): void
    {
        if (!self::isValidName($name)) {
            return;
        }

        foreach (['', '-thumb'] as $suffix) {
            $this->remove($this->path($this->variantName($name, $suffix)));
        }
    }

    public function url(string $name, bool $thumbnail = false): string
    {
        if (!self::isValidName($name)) {
            return '';
        }

        return BasePath::get() . '/' . trim($this->urlPath, '/') . '/' . $this->variantName($name, $thumbnail ? '-thumb' : '');
    }

    public function exists(string $name, bool $thumbnail = false): bool
    {
        return self::isValidName($name) && is_file($this->path($this->variantName($name, $thumbnail ? '-thumb' : '')));
    }

    private function variantName(string $name, string $suffix): string
    {
        return $suffix === '' ? $name : preg_replace('/\.(jpg|png|webp)\z/', $suffix . '.$1', $name) ?? $name;
    }

    private function path(string $file): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $file;
    }

    private function decode(string $bytes): GdImage|false
    {
        set_error_handler(static fn (): bool => true);

        try {
            return imagecreatefromstring($bytes);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Phones store a photo sideways and record the turn in EXIF; the metadata is dropped, so the turn is applied
     * to the pixels first.
     */
    private function upright(GdImage $image, int $type, string $sourcePath): GdImage
    {
        if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data') || !function_exists('imagerotate')) {
            return $image;
        }

        set_error_handler(static fn (): bool => true);

        try {
            $exif = exif_read_data($sourcePath);
        } finally {
            restore_error_handler();
        }

        $angle = match (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $turned = imagerotate($image, $angle, 0);

        return $turned instanceof GdImage ? $turned : $image;
    }

    /**
     * @param array{x: float, y: float, w: float, h: float} $crop
     */
    private function cropped(GdImage $image, array $crop): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $left = min($width - 1, max(0, (int) round($crop['x'] * $width)));
        $top = min($height - 1, max(0, (int) round($crop['y'] * $height)));
        $rect = [
            'x' => $left,
            'y' => $top,
            'width' => min($width - $left, max(1, (int) round($crop['w'] * $width))),
            'height' => min($height - $top, max(1, (int) round($crop['h'] * $height))),
        ];

        if ($rect['width'] < self::MIN_SIDE || $rect['height'] < self::MIN_SIDE) {
            throw new CoverImageException('The chosen area is too small. Choose a larger part of the picture.');
        }

        $part = imagecrop($image, $rect);

        return $part instanceof GdImage ? $part : throw new CoverImageException('That picture could not be cropped.');
    }

    /**
     * @param array{int, int} $box
     */
    private function scaled(GdImage $source, array $box): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = min($box[0] / $width, $box[1] / $height, 1.0);
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $target = imagecreatetruecolor($newWidth, $newHeight);

        if ($target === false) {
            throw new CoverImageException('That picture could not be processed.');
        }

        // Keep transparency for the formats that have it; a JPEG simply has none to keep.
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 255, 255, 255, 127));
        imagealphablending($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $target;
    }

    private function write(GdImage $image, int $type, string $path): void
    {
        if ($type === IMAGETYPE_JPEG) {
            // JPEG has no alpha; draw on white so a transparent area is not left black.
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));

            if ($flat === false) {
                throw new CoverImageException('That picture could not be processed.');
            }

            imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            $image = $flat;
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        $saved = match ($type) {
            IMAGETYPE_PNG => imagepng($image, $path, 6),
            IMAGETYPE_WEBP => imagewebp($image, $path, 85),
            default => imagejpeg($image, $path, 85),
        };

        if (!$saved || !is_file($path)) {
            throw new CoverImageException('The picture could not be saved. Please try again.');
        }

        // The web server reads these, but nobody needs to change them.
        chmod($path, 0644);
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new CoverImageException('The picture could not be saved. Please try again.');
        }

        if (!is_writable($this->directory)) {
            throw new CoverImageException('The picture could not be saved. Please try again.');
        }
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }

    private static function megabytes(int $bytes): string
    {
        $value = $bytes / 1048576;

        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || preg_match('/^(\d+)\s*([kmg]?)b?\z/i', $value, $match) !== 1) {
            return 0;
        }

        return (int) $match[1] * match (strtolower($match[2])) {
            'g' => 1073741824,
            'm' => 1048576,
            'k' => 1024,
            default => 1,
        };
    }
}
