<?php

/**
 * The only place that says which publication state may follow which.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Models\PublicationState;

/**
 * Pure state graph, with no knowledge of users: who may take a step is decided by StoryService from the
 * caller's role and ownership. Any step not listed here does not exist, so no code path can invent one.
 */
final class PublicationRules
{
    /** Putting a story forward: straight to readers when the writer is trusted, otherwise into the queue. */
    public static function submit(PublicationState $from, bool $direct): ?PublicationState
    {
        return match ($from) {
            PublicationState::Draft, PublicationState::Unpublished => $direct ? PublicationState::Published : PublicationState::Pending,
            PublicationState::Pending => $direct ? PublicationState::Published : null,
            PublicationState::Published => null,
        };
    }

    /** Taking a story back: a published story leaves public view, a queued one returns to draft. */
    public static function withdraw(PublicationState $from): ?PublicationState
    {
        return match ($from) {
            PublicationState::Published => PublicationState::Unpublished,
            PublicationState::Pending => PublicationState::Draft,
            default => null,
        };
    }

    public static function approve(PublicationState $from): ?PublicationState
    {
        return $from === PublicationState::Pending ? PublicationState::Published : null;
    }

    /** A rejected story is an ordinary draft that carries the reason it was sent back. */
    public static function reject(PublicationState $from): ?PublicationState
    {
        return $from === PublicationState::Pending ? PublicationState::Draft : null;
    }
}
