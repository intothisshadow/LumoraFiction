<?php

/**
 * What an Author has decided about readers downloading one story.
 *
 * @package LumoraFiction
 * @subpackage Models
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Models;

enum DownloadChoice: string
{
    case UseDefault = 'default';
    case Allow = 'allow';
    case Refuse = 'refuse';

    public function label(): string
    {
        return match ($this) {
            self::UseDefault => 'Use my default for all my stories',
            self::Allow => 'Allow downloads of this story',
            self::Refuse => 'Do not allow downloads of this story',
        };
    }
}
