<?php

/**
 * Procedural plugin API: add_action(), do_action(), add_filter(), apply_filters() and their remove_* counterparts.
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

use LumoraFiction\Core\Hooks\Hooks;

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10): void
    {
        Hooks::instance()->addAction($hook, $callback, $priority);
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        Hooks::instance()->doAction($hook, ...$args);
    }
}

if (!function_exists('remove_action')) {
    function remove_action(string $hook, callable $callback, int $priority = 10): bool
    {
        return Hooks::instance()->removeAction($hook, $callback, $priority);
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10): void
    {
        Hooks::instance()->addFilter($hook, $callback, $priority);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return Hooks::instance()->applyFilters($hook, $value, ...$args);
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter(string $hook, callable $callback, int $priority = 10): bool
    {
        return Hooks::instance()->removeFilter($hook, $callback, $priority);
    }
}
