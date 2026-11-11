<?php

/**
 * An ordered sequence of stories by one author.
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

final readonly class Series
{
    public function __construct(
        public int $id,
        public int $ownerId,
        public string $title,
        public string $slug,
        public string $summary,
        public WorkStatus $workStatus,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
