<?php

/**
 * Removes the installer's own folder once installation has finished.
 *
 * @package LumoraFiction
 * @subpackage Installer
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Installer;

/**
 * An installer left on a live site is an attack surface, so it deletes itself. This is best effort:
 * a locked-down host may refuse, which is an expected outcome and not an error, and the caller
 * tells the administrator to remove the folder by hand. Never run the installer against the
 * project's own source tree, or this deletes the real installer.
 */
final class InstallerCleanup
{
    /**
     * Removes the directory and everything in it, including the script that is running this code.
     * On Unix, deleting an open file only removes its directory entry, so the process carries on.
     *
     * @return bool true only if the whole tree was removed; it stops at the first failure
     */
    public function remove(string $directory): bool
    {
        if (!is_dir($directory) || is_link($directory)) {
            return !file_exists($directory) && !is_link($directory);
        }

        $items = scandir($directory);

        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            // A symlink is removed as a link and never followed, so a link inside the folder can
            // never cause files outside it to be deleted.
            if (is_dir($path) && !is_link($path)) {
                if (!$this->remove($path)) {
                    return false;
                }
            } elseif (!$this->attempt(static fn (): bool => unlink($path))) {
                return false;
            }
        }

        return $this->attempt(static fn (): bool => rmdir($directory));
    }

    /**
     * @param callable(): bool $operation
     */
    private function attempt(callable $operation): bool
    {
        // A refused delete raises a warning, which the application turns into an exception.
        set_error_handler(static fn (): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
