<?php

/**
 * An administrator's per-writer decision about whether stories need approval.
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
 * No case means "follow the site-wide rule", so that is stored as NULL rather than a third value.
 */
enum PublishingOverride: string
{
    case Direct = 'direct';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Publishes without review',
            self::Review => 'Every story is reviewed',
        };
    }
}
