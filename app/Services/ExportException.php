<?php

/**
 * An export that could not be made or that the person may not ask for.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use RuntimeException;

/**
 * The message is shown to the person who asked, so it says what to do next and never names a path.
 */
final class ExportException extends RuntimeException
{
}
