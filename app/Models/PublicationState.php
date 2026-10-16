<?php

/**
 * Whether a story or chapter is visible to readers, and if not, why.
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
 * Chapters use only Draft, Published and Unpublished; Pending is the story-level "waiting for approval" state.
 */
enum PublicationState: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Published = 'published';
    case Unpublished = 'unpublished';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Pending => 'Pending approval',
            self::Published => 'Published',
            self::Unpublished => 'Unpublished',
        };
    }
}
