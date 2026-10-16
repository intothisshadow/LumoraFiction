<?php

/**
 * How a chapter's stored text is interpreted before it is shown.
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
 * The content renderer is the one place that branches on this value; everything else just passes it along.
 */
enum ContentFormat: string
{
    case Html = 'html';
    case Markdown = 'markdown';
    case Plain = 'plain';

    public function label(): string
    {
        return match ($this) {
            self::Html => 'Visual / HTML',
            self::Markdown => 'Markdown',
            self::Plain => 'Plain text',
        };
    }
}
