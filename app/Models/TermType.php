<?php

/**
 * The kinds of classification a story can carry, and the rules that differ between them.
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
 * Ratings and warnings are separate kinds on purpose: a rating says who a story suits, a warning says what
 * it contains, and neither is ever worked out from the other.
 */
enum TermType: string
{
    case Fandom = 'fandom';
    case Character = 'character';
    case Relationship = 'relationship';
    case Genre = 'genre';
    case Tag = 'tag';
    case Category = 'category';
    case Rating = 'rating';
    case Warning = 'warning';
    case RelationshipCategory = 'relationship_category';

    public function label(): string
    {
        return match ($this) {
            self::Fandom => 'Fandom',
            self::Character => 'Character',
            self::Relationship => 'Relationship',
            self::Genre => 'Genre',
            self::Tag => 'Tag',
            self::Category => 'Category',
            self::Rating => 'Rating',
            self::Warning => 'Archive warning',
            self::RelationshipCategory => 'Relationship category',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Fandom => 'Fandoms',
            self::Character => 'Characters',
            self::Relationship => 'Relationships',
            self::Genre => 'Genres',
            self::Tag => 'Tags',
            self::Category => 'Categories',
            self::Rating => 'Ratings',
            self::Warning => 'Archive warnings',
            self::RelationshipCategory => 'Relationship categories',
        };
    }

    /**
     * Characters and relationships may belong to a fandom, so the same name in two fandoms stays two terms.
     */
    public function belongsToFandom(): bool
    {
        return $this === self::Character || $this === self::Relationship;
    }

    /**
     * Whether a story can carry only one term of this kind.
     */
    public function isSingleChoice(): bool
    {
        return $this === self::Rating;
    }

    /**
     * Writers may add new terms of these kinds while writing; the rest are lists the administrator curates.
     */
    public function writersMayPropose(): bool
    {
        return match ($this) {
            self::Fandom, self::Character, self::Relationship, self::Tag => true,
            default => false,
        };
    }

    /**
     * The most terms of this kind one story may carry, so a story cannot be stuffed with hundreds of tags.
     */
    public function maximumPerStory(): int
    {
        return match ($this) {
            self::Fandom => 10,
            self::Character => 30,
            self::Relationship => 15,
            self::Genre => 6,
            self::Tag => 30,
            self::Category => 5,
            self::Rating => 1,
            self::Warning => 10,
            self::RelationshipCategory => 6,
        };
    }

    /**
     * Fandom fields belong to fanfiction and original-fiction categories to original work; everything else
     * is useful to both. $mode is the archive mode setting: both, fanfiction or original.
     */
    public function availableIn(string $mode): bool
    {
        return match ($this) {
            self::Fandom, self::Character, self::Relationship, self::RelationshipCategory => $mode !== 'original',
            self::Category => $mode !== 'fanfiction',
            default => true,
        };
    }
}
