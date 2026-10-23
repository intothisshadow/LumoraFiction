<?php

/**
 * A short message for one person, with the admin page that handles it.
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

final readonly class Notification
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $kind,
        public ?int $storyId,
        public string $message,
        public string $path,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $readAt,
    ) {
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }
}
