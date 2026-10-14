<?php

/**
 * Priority-ordered action and filter registry behind the add_action()/add_filter() plugin API.
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
 * Lower priorities run first; callbacks with the same priority run in the order they were added.
 * Dispatch iterates a snapshot, so a callback that adds or removes callbacks for the hook being
 * run takes effect from the next dispatch, never mid-loop.
 */
final class HookManager
{
    /** @var array<string, array<int, list<callable>>> */
    private array $actions = [];

    /** @var array<string, array<int, list<callable>>> */
    private array $filters = [];

    public function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        $this->actions[$hook][$priority][] = $callback;
        ksort($this->actions[$hook]);
    }

    public function doAction(string $hook, mixed ...$args): void
    {
        foreach ($this->actions[$hook] ?? [] as $callbacks) {
            foreach ($callbacks as $callback) {
                $callback(...$args);
            }
        }
    }

    public function removeAction(string $hook, callable $callback, int $priority = 10): bool
    {
        return $this->remove($this->actions, $hook, $callback, $priority);
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        $this->filters[$hook][$priority][] = $callback;
        ksort($this->filters[$hook]);
    }

    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        foreach ($this->filters[$hook] ?? [] as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = $callback($value, ...$args);
            }
        }

        return $value;
    }

    public function removeFilter(string $hook, callable $callback, int $priority = 10): bool
    {
        return $this->remove($this->filters, $hook, $callback, $priority);
    }

    public function hasAction(string $hook): bool
    {
        return !empty($this->actions[$hook]);
    }

    public function hasFilter(string $hook): bool
    {
        return !empty($this->filters[$hook]);
    }

    /**
     * @param array<string, array<int, list<callable>>> $registry
     */
    private function remove(array &$registry, string $hook, callable $callback, int $priority): bool
    {
        foreach ($registry[$hook][$priority] ?? [] as $index => $registered) {
            if ($registered === $callback) {
                unset($registry[$hook][$priority][$index]);

                $registry[$hook][$priority] = array_values($registry[$hook][$priority]);

                if ($registry[$hook][$priority] === []) {
                    unset($registry[$hook][$priority]);
                }

                if ($registry[$hook] === []) {
                    unset($registry[$hook]);
                }

                return true;
            }
        }

        return false;
    }
}
