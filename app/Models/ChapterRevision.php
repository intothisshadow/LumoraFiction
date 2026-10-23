<?php

/**
 * An earlier version of a chapter, kept so an author can go back to it.
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

final readonly class ChapterRevision
{
    public function __construct(
        public int $id,
        public int $chapterId,
        public string $title,
        public string $content,
        public ContentFormat $format,
        public string $notes,
        public int $wordCount,
        public ?int $createdBy,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
