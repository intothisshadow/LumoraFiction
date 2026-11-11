<?php

/**
 * A curated group of stories, in no particular order, that may hold other people's work.
 *
 * @package LumoraFiction
 * @subpackage Models
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Models;

use DateTimeImmutable;

final readonly class Collection
{
    public function __construct(
        public int $id,
        public int $ownerId,
        public string $title,
        public string $slug,
        public string $description,
        public bool $isPublic,
        public bool $acceptsSubmissions,
        public bool $requiresApproval,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
