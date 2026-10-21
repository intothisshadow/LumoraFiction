<?php

/**
 * Why a plugin was not loaded.
 *
 * @package LumoraFiction
 * @subpackage Plugin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Plugin;

use Throwable;

final readonly class PluginLoadFailure
{
    /**
     * @param string $message short and free of server folder names, so it is safe to show an administrator
     * @param Throwable|null $exception what was thrown, for the error log; null when nothing was run
     */
    public function __construct(
        public string $message,
        public ?Throwable $exception = null,
    ) {
    }
}
