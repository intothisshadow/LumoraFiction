<?php

/**
 * The picture store for pictures an administrator uploads through a theme option, such as a header picture.
 *
 * @package LumoraFiction
 * @subpackage Media
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Media;

/**
 * Wide pictures are drawn behind a band, so the full size is wider than a cover while the smaller one is only what
 * the options screen shows as a preview.
 */
final class ThemePictureStore extends ImageStore
{
    public const WIDE = [1600, 1200];

    public const PREVIEW = [640, 480];

    public function __construct(string $directory, string $urlPath = 'content/uploads/theme-headers/uploaded')
    {
        parent::__construct($directory, $urlPath, ['' => self::WIDE, '-thumb' => self::PREVIEW]);
    }
}
