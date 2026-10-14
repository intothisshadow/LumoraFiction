<?php

/**
 * Thrown when a database connection cannot be established or its settings are unusable.
 *
 * @package LumoraFiction
 * @subpackage Database
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Database;

use RuntimeException;

/**
 * The message is always generic and safe to show; the underlying driver error, which can
 * contain hostnames and usernames, is attached as the previous exception for logging only.
 */
final class DatabaseConnectionException extends RuntimeException
{
}
