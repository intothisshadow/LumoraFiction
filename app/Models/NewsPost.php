<?php

/**
 * A news post: a titled, dated announcement from the staff.
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

final readonly class NewsPost
{
    public function __construct(
        public int $id,
        public int $authorId,
        public string $title,
        public string $slug,
        public string $content,
        public ContentFormat $format,
        public PublicationState $state,
        public bool $pinned,
        public ?DateTimeImmutable $publishedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function isPublished(): bool
    {
        return $this->state === PublicationState::Published;
    }
}
