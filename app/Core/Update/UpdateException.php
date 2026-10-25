<?php

/**
 * A problem with an update or a backup, worded for the administrator who reads it.
 *
 * @package LumoraFiction
 * @subpackage Update
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Update;

use RuntimeException;

/**
 * The message is shown on the Updates screen and written to the update log, so it never holds a filesystem path
 * or a database error: those go to the error log through the previous exception.
 */
final class UpdateException extends RuntimeException
{
}
