<?php

/**
 * A problem reaching or reading the eFiction database, worded for the administrator.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\EfictionImporter;

use RuntimeException;

/**
 * The message is safe to show: it is written here from a fixed set of sentences and never copies the driver's own text,
 * which can name the user and host of the connection.
 */
final class SourceException extends RuntimeException
{
}
