<?php

/**
 * Thrown when an uploaded cover cannot be accepted; the message is written for the person who uploaded it.
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

use RuntimeException;

final class CoverImageException extends RuntimeException
{
}
