<?php

/**
 * A story, as read from the database.
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

final readonly class Story
{
    public function __construct(
        public int $id,
        public int $ownerId,
        public string $title,
        public string $slug,
        public string $summary,
        public string $notes,
        public string $disclaimer,
        public string $language,
        public WorkStatus $workStatus,
        public PublicationState $state,
        public int $wordCount,
        public ?DateTimeImmutable $publishedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public string $rejectionReason = '',
        public ?DateTimeImmutable $rejectedAt = null,
        public string $coverFile = '',
        public string $coverAlt = '',
    ) {
    }

    public function hasCover(): bool
    {
        return $this->coverFile !== '';
    }

    /**
     * A rejected story is a draft that still carries the reason; submitting it again clears the reason.
     */
    public function wasRejected(): bool
    {
        return $this->state === PublicationState::Draft && $this->rejectionReason !== '';
    }
}
