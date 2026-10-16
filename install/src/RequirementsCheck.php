<?php

/**
 * Checks the server meets the requirements before the installer attempts anything.
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

use Closure;
use LumoraFiction\Core\Requirements;

/**
 * A missing extension or an unwritable folder produces a specific message here instead of a
 * confusing failure partway through. The PHP version and extension lookups can be replaced, so both
 * failure branches are testable without running under an unsupported PHP or with an extension off.
 */
final class RequirementsCheck
{
    public const MINIMUM_PHP_VERSION = Requirements::MINIMUM_PHP_VERSION;

    /** @var list<string> */
    public const REQUIRED_EXTENSIONS = Requirements::REQUIRED_EXTENSIONS;

    public function __construct(
        private readonly string $root,
        private readonly string $phpVersion = PHP_VERSION,
        private readonly ?Closure $extensionLoaded = null,
    ) {
    }

    /**
     * @return list<string> plain-language problems; empty when every requirement is met
     */
    public function check(): array
    {
        $problems = [];

        if (version_compare($this->phpVersion, self::MINIMUM_PHP_VERSION, '<')) {
            $problems[] = sprintf('PHP %s or newer is required. This server is running PHP %s.', self::MINIMUM_PHP_VERSION, $this->phpVersion);
        }

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (!($this->extensionLoaded ?? extension_loaded(...))($extension)) {
                $problems[] = sprintf('The PHP "%s" extension is required but is not enabled.', $extension);
            }
        }

        foreach ($this->requiredDirectories() as $label => $path) {
            $this->createRuntimeDirectoryIfMissing($label, $path);

            if (!is_dir($path)) {
                $problems[] = sprintf('The "%s" folder is missing.', $label);
            } elseif (!is_writable($path)) {
                $problems[] = sprintf('The "%s" folder is not writable by the web server.', $label);
            }
        }

        return $problems;
    }

    /**
     * Empty folders are easily lost when files are uploaded (FTP clients and some archive tools
     * skip them), and everything under storage/ holds only runtime files, so a missing one is
     * created rather than failing the install over it.
     */
    private function createRuntimeDirectoryIfMissing(string $label, string $path): void
    {
        if (is_dir($path) || !str_starts_with($label, 'storage/') || !is_writable(dirname($path))) {
            return;
        }

        mkdir($path, 0755, true);
    }

    /**
     * Labels are shown to the person installing, so they never include the server's filesystem path.
     *
     * @return array<string, string> label => absolute path
     */
    private function requiredDirectories(): array
    {
        return [
            'config' => $this->root . '/config',
            'storage/logs' => $this->root . '/storage/logs',
            'storage/cache' => $this->root . '/storage/cache',
            'storage/backups' => $this->root . '/storage/backups',
            'storage/sessions' => $this->root . '/storage/sessions',
            'content/uploads' => $this->root . '/content/uploads',
        ];
    }
}
