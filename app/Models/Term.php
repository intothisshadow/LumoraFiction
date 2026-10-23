<?php

/**
 * A single fandom, character, tag, rating or other classification term.
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

final readonly class Term
{
    public function __construct(
        public int $id,
        public TermType $type,
        public int $parentId,
        public string $name,
        public string $slug,
        public bool $reviewed,
        public int $storyCount,
        public ?int $createdBy,
        public DateTimeImmutable $createdAt,
        public string $description = '',
        public int $sortOrder = 0,
        public bool $hidden = false,
        public string $presetKey = '',
    ) {
    }
}
