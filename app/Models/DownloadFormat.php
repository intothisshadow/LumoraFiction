<?php

/**
 * A file format a story can be downloaded in.
 *
 * @package LumoraFiction
 * @subpackage Models
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Models;

enum DownloadFormat: string
{
    case Epub = 'epub';
    case Pdf = 'pdf';
    case Html = 'html';

    public function label(): string
    {
        return match ($this) {
            self::Epub => 'EPUB',
            self::Pdf => 'PDF',
            self::Html => 'HTML',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Epub => 'For e-readers and reading apps',
            self::Pdf => 'For printing or reading on any device',
            self::Html => 'One web page that opens in any browser',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Epub => 'application/epub+zip',
            self::Pdf => 'application/pdf',
            self::Html => 'text/html; charset=utf-8',
        };
    }
}
