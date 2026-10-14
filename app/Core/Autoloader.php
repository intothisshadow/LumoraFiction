<?php

/**
 * Minimal PSR-4-style class autoloader, used so the application never needs Composer on the host.
 *
 * @package LumoraFiction
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core;

final class Autoloader
{
    /** @var array<string, string> */
    private array $prefixes = [];

    public function addNamespace(string $prefix, string $baseDirectory): void
    {
        $prefix = trim($prefix, '\\') . '\\';
        $this->prefixes[$prefix] = rtrim($baseDirectory, '/') . '/';
    }

    public function register(): void
    {
        spl_autoload_register($this->loadClass(...));
    }

    private function loadClass(string $class): void
    {
        foreach ($this->prefixes as $prefix => $baseDirectory) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));

            // Class names come from code, but reject traversal sequences anyway so a
            // crafted name can never resolve to a file outside the namespace directory.
            if (str_contains($relative, '..')) {
                return;
            }

            $file = $baseDirectory . str_replace('\\', '/', $relative) . '.php';

            if (is_file($file)) {
                require $file;

                return;
            }
        }
    }
}
