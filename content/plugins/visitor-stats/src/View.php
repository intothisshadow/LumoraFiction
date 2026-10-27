<?php

/**
 * Renders one of the plugin's view files to a string.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\VisitorStats;

use Throwable;

final class View
{
    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $file, array $data): string
    {
        return (static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();

            try {
                require $__file;
            } catch (Throwable $exception) {
                ob_end_clean();

                throw $exception;
            }

            return (string) ob_get_clean();
        })($file, $data);
    }
}
