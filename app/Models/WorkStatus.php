<?php

/**
 * Where a story is in its life as a piece of work: still being written, finished, or paused.
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
 * This is separate from the publication state: a story can be published and still ongoing.
 */
enum WorkStatus: string
{
    case Ongoing = 'ongoing';
    case Completed = 'completed';
    case OnHiatus = 'on_hiatus';
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::Ongoing => 'Ongoing',
            self::Completed => 'Completed',
            self::OnHiatus => 'On hiatus',
            self::Abandoned => 'Abandoned',
        };
    }
}
