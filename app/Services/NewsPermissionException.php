<?php

/**
 * Thrown when someone may not do what they asked to a news post, or the post does not exist.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use RuntimeException;

/**
 * A post that is missing and one the person may not touch give the same answer, so the message never says which.
 */
final class NewsPermissionException extends RuntimeException
{
}
