<?php

/**
 * One reader comment under a chapter.
 *
 * @package LumoraFiction
 * @subpackage Models
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

namespace LumoraFiction\Models;

use DateTimeImmutable;

final readonly class Comment
{
    public function __construct(
        public int $id,
        public int $storyId,
        public int $chapterId,
        public int $userId,
        public string $body,
        public CommentStatus $status,
        public int $reportCount,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $editedAt = null,
        public ?int $parentId = null,
        public ?string $guestName = null,
    ) {
    }

    /** An imported guest review: owned by the importing account, shown under the guest's own name. */
    public function isGuest(): bool
    {
        return $this->guestName !== null;
    }

    public function isReply(): bool
    {
        return $this->parentId !== null;
    }

    public function wasEdited(): bool
    {
        return $this->editedAt !== null;
    }
}
