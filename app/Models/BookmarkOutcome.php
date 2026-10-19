<?php

/**
 * The result of asking to bookmark a story or a chapter.
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

enum BookmarkOutcome: string
{
    case Added = 'added';
    case AlreadySaved = 'already_saved';
    case NotAvailable = 'not_available';
    case ListFull = 'list_full';
    case NotAllowed = 'not_allowed';

    /**
     * The words shown to the reader. A story that cannot be bookmarked is described the same way whether it is
     * hidden or missing, so the answer never reveals that a draft exists.
     */
    public function message(): string
    {
        return match ($this) {
            self::Added => 'Added to your bookmarks.',
            self::AlreadySaved => 'That is already in your bookmarks.',
            self::NotAvailable => 'That story or chapter is not available.',
            self::ListFull => 'Your bookmark list is full. Remove one before adding another.',
            self::NotAllowed => 'Your account cannot save bookmarks.',
        };
    }

    public function succeeded(): bool
    {
        return $this === self::Added || $this === self::AlreadySaved;
    }
}
