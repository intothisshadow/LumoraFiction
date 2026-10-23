<?php

/**
 * Raised when a theme ZIP is refused or cannot be put in place.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Theme;

use RuntimeException;

/**
 * The message is always a plain sentence written for the administrator, with no paths and no library wording, so
 * the screen can show it as it is.
 */
final class ThemeInstallException extends RuntimeException
{
}
