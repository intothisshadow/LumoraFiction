<?php

/**
 * An administrator's change to an account that the rules refuse.
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
 * The message is written for the administrator and is safe to show as it is.
 */
final class UserAdminException extends RuntimeException
{
}
