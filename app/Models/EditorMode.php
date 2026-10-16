<?php

/**
 * The four ways a writer can edit a chapter.
 *
 * @package LumoraFiction
 * @subpackage Models
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Models;

/**
 * Visual and HTML both store HTML, so a chapter can move between those two without any conversion; only
 * moving to or from Markdown or plain text changes the stored text.
 */
enum EditorMode: string
{
    case Visual = 'visual';
    case Markdown = 'markdown';
    case Html = 'html';
    case Plain = 'plain';

    public function label(): string
    {
        return match ($this) {
            self::Visual => 'Visual',
            self::Markdown => 'Markdown',
            self::Html => 'HTML',
            self::Plain => 'Plain text',
        };
    }

    public function format(): ContentFormat
    {
        return match ($this) {
            self::Visual, self::Html => ContentFormat::Html,
            self::Markdown => ContentFormat::Markdown,
            self::Plain => ContentFormat::Plain,
        };
    }

    /**
     * The mode a chapter naturally opens in when its stored format is known.
     */
    public static function forFormat(ContentFormat $format): self
    {
        return match ($format) {
            ContentFormat::Html => self::Visual,
            ContentFormat::Markdown => self::Markdown,
            ContentFormat::Plain => self::Plain,
        };
    }
}
