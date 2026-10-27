<?php

/**
 * A download could not be made; the message is written for the reader and is safe to show.
 *
 * @package LumoraFiction
 * @subpackage Download
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Download;

use RuntimeException;

final class DownloadException extends RuntimeException
{
}
