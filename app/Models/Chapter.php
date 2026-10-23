<?php

/**
 * A chapter, as read from the database.
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

use DateTimeImmutable;

final readonly class Chapter
{
    public function __construct(
        public int $id,
        public int $storyId,
        public int $number,
        public string $title,
        public string $content,
        public ContentFormat $format,
        public string $notes,
        public int $wordCount,
        public PublicationState $state,
        public ?DateTimeImmutable $publishedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $trashedAt = null,
    ) {
    }

    public function isTrashed(): bool
    {
        return $this->trashedAt !== null;
    }
}
