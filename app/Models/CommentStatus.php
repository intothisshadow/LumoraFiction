<?php

/**
 * Where a comment stands: shown to readers, waiting for a moderator, hidden by one, or marked as spam.
 *
 * @package LumoraFiction
 * @subpackage Models
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

namespace LumoraFiction\Models;

enum CommentStatus: string
{
    case Published = 'published';
    case Pending = 'pending';
    case Hidden = 'hidden';
    case Spam = 'spam';

    public function label(): string
    {
        return match ($this) {
            self::Published => 'Published',
            self::Pending => 'Pending',
            self::Hidden => 'Hidden',
            self::Spam => 'Spam',
        };
    }
}
