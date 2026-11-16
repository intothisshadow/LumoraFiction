<?php

/**
 * One file sent with a form, as PHP stored it for the request.
 *
 * @package LumoraFiction
 * @subpackage Http
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Http;

final readonly class UploadedFile
{
    public function __construct(
        public string $path,
        public int $error,
        public int $size,
        /** The name the person's computer gave the file, cleaned for showing in a message; never used as a path. */
        public string $name = '',
    ) {
    }

    /**
     * A name that is safe to print and short: the part after any folder, without control characters.
     */
    public static function cleanName(mixed $name): string
    {
        if (!is_string($name)) {
            return '';
        }

        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1f\x7f]/u', '', $name);

        return mb_substr($name, 0, 100, 'UTF-8');
    }

    /**
     * Whether the person chose a file at all; a form with an empty file field still sends this entry.
     */
    public function wasSent(): bool
    {
        return $this->error !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * What to tell the person when PHP could not take the file, or an empty string when it did.
     */
    public function problem(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_OK => '',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is larger than this server accepts.',
            UPLOAD_ERR_PARTIAL => 'The file was only partly uploaded. Please try again.',
            default => 'The file could not be uploaded. Please try again.',
        };
    }
}
