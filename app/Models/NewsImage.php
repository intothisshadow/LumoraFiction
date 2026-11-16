<?php

/**
 * One uploaded news picture: the stored file names and sizes. Which post shows it, and with what alternative text, is the post's own business.
 *
 * @package LumoraFiction
 * @subpackage Models
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

namespace LumoraFiction\Models;

use DateTimeImmutable;

final readonly class NewsImage
{
    public function __construct(
        public int $id,
        public ?int $ownerId,
        public string $fileName,
        public int $width,
        public int $height,
        public int $thumbWidth,
        public int $thumbHeight,
        public int $sizeBytes,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
