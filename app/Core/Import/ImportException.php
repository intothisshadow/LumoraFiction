<?php

/**
 * A story import that cannot go on, or a file that cannot be read as a downloaded work.
 *
 * @package LumoraFiction
 * @subpackage Import
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Import;

use RuntimeException;

/**
 * The message is shown to the person who uploaded the file, so it says what to do next and never names a path.
 */
final class ImportException extends RuntimeException
{
}
