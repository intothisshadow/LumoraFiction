<?php

/**
 * Thrown when a database migration cannot be applied.
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

final class MigrationException extends RuntimeException
{
}
