<?php

/**
 * Static bridge that exposes one HookManager to the procedural plugin and theme API.
 *
 * @package LumoraFiction
 * @subpackage Hooks
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Hooks;

/**
 * Themes and plugins call add_action() and friends without access to the container, so they need
 * a global entry point. Core services receive the HookManager through their constructors instead.
 */
final class Hooks
{
    private static ?HookManager $instance = null;

    public static function set(HookManager $manager): void
    {
        self::$instance = $manager;
    }

    public static function instance(): HookManager
    {
        return self::$instance ??= new HookManager();
    }
}
