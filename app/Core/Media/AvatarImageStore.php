<?php

/**
 * An author's picture: one square file, 100 by 100 pixels, under a generated name.
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

final class AvatarImageStore extends ImageStore
{
    /** The side of the stored picture. A source smaller than this is refused, so a picture is never enlarged. */
    public const SIDE = 100;

    public function __construct(string $directory, string $urlPath = 'content/uploads/avatars')
    {
        parent::__construct($directory, $urlPath, ['' => [self::SIDE, self::SIDE]], true);
    }
}
