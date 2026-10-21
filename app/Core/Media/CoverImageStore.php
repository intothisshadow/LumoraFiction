<?php

/**
 * A story's cover picture: a full-size and a thumbnail file under a generated name.
 *
 * @package LumoraFiction
 * @subpackage Media
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Media;

final class CoverImageStore extends ImageStore
{
    public function __construct(string $directory, string $urlPath = 'content/uploads/covers')
    {
        parent::__construct($directory, $urlPath, ['' => self::FULL, '-thumb' => self::THUMB]);
    }
}
