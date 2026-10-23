<?php

/**
 * What happened when a reader asked to follow a story.
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

enum FollowOutcome: string
{
    case Followed = 'followed';
    case AlreadyFollowing = 'already_following';
    case NotAvailable = 'not_available';
    case ListFull = 'list_full';
    case NotAllowed = 'not_allowed';

    /**
     * The words shown to the reader. A story that cannot be followed is described the same way whether it is hidden
     * or missing, so the answer never reveals that a draft exists.
     */
    public function message(): string
    {
        return match ($this) {
            self::Followed => 'You are now following this story.',
            self::AlreadyFollowing => 'You are already following this story.',
            self::NotAvailable => 'That story is not available.',
            self::ListFull => 'You are following as many stories as the site allows. Stop following one before adding another.',
            self::NotAllowed => 'Your account cannot follow stories.',
        };
    }

    public function succeeded(): bool
    {
        return $this === self::Followed || $this === self::AlreadyFollowing;
    }
}
