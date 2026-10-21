<?php

/**
 * Includes a plugin's main file and reports why it could not.
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

use LumoraFiction\Core\Kernel;
use Throwable;

/**
 * Anything a plugin throws while its file runs, including a parse error or a warning (the error handler turns
 * warnings into exceptions), skips that plugin and never the site. A plugin that dies with a fatal error that PHP
 * cannot catch, such as running out of memory, cannot be skipped this way; the site owner removes its folder.
 */
final class PluginLoader
{
    public function __construct(
        private readonly Kernel $kernel,
        private readonly PluginCatalog $catalog,
        private readonly string $appVersion,
        private readonly string $root,
    ) {
    }

    /**
     * Returns null when the plugin ran, otherwise the reason it did not.
     */
    public function load(string $slug): ?PluginLoadFailure
    {
        $info = $this->catalog->find($slug);

        if ($info === null) {
            return new PluginLoadFailure('The plugin\'s folder or main file is missing, or its header cannot be read.');
        }

        $unmet = $info->unmetRequirements(PHP_VERSION, $this->appVersion);

        if ($unmet !== []) {
            return new PluginLoadFailure(implode(' ', $unmet));
        }

        try {
            self::run($info->mainFile, $this->kernel);
        } catch (Throwable $exception) {
            return new PluginLoadFailure($this->describe($exception), $exception);
        }

        return null;
    }

    /**
     * A separate static method, so the plugin's file sees only $kernel and not the loader's own variables.
     */
    private static function run(string $file, Kernel $kernel): void
    {
        require $file;
    }

    /**
     * Server folder names are removed because the text can reach an administrator's screen.
     */
    private function describe(Throwable $exception): string
    {
        $text = $exception::class . ': ' . $exception->getMessage();
        $text = str_replace(rtrim($this->root, '/') . '/', '', $text);
        $text = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text));

        return mb_substr($text, 0, 300);
    }
}
