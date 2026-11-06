<?php

/**
 * A static page: staff-written site content such as About or the privacy policy.
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

final readonly class Page
{
    public function __construct(
        public int $id,
        public string $title,
        public string $slug,
        public string $content,
        public ContentFormat $format,
        public PublicationState $state,
        public bool $inMenu,
        public bool $inFooter,
        public int $sortOrder,
        public string $description,
        public bool $noindex,
        public ?int $editedBy,
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
