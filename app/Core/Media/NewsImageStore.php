<?php

/**
 * A news post's featured picture: a full-size and a list-sized file under a generated name.
 *
 * @package LumoraFiction
 * @subpackage Media
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Media;

/**
 * Goes through exactly the checks, re-encoding and size limit of a story cover, so no second upload path has to be
 * audited; only the boxes differ, because a news picture is wide.
 */
final class NewsImageStore extends ImageStore
{
    public const WIDE = [1200, 900];

    public const LIST = [400, 300];

    public function __construct(string $directory, string $urlPath = 'content/uploads/news')
    {
        parent::__construct($directory, $urlPath, ['' => self::WIDE, '-thumb' => self::LIST]);
    }
}
