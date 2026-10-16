<?php

/**
 * Builds addresses inside the admin area, including the install's base directory.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Admin;

use InvalidArgumentException;
use LumoraFiction\Core\Http\BasePath;

final class AdminUrl
{
    /**
     * The address of an admin screen, for example to('settings/archive'). With no argument it is the admin home.
     */
    public static function to(string $path = ''): string
    {
        return BasePath::get() . '/admin/' . self::clean($path);
    }

    /**
     * The address of a file under admin/assets/, with a version so a browser fetches a changed file.
     */
    public static function asset(string $path, string $version = ''): string
    {
        return BasePath::get() . '/admin/assets/' . self::clean($path) . ($version !== '' ? '?v=' . rawurlencode($version) : '');
    }

    /**
     * Paths are written in code, never taken from a visitor, so anything unusual is a programming
     * mistake and is refused rather than quietly encoded.
     */
    private static function clean(string $path): string
    {
        $path = ltrim($path, '/');

        if (($path !== '' && preg_match('#^[A-Za-z0-9_./-]+\z#', $path) !== 1) || str_contains($path, '..')) {
            throw new InvalidArgumentException('Invalid admin path.');
        }

        return $path;
    }
}
