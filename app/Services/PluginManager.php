<?php

/**
 * Which plugins are active, loading them, and turning them on and off.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Errors\ErrorHandler;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Plugin\PluginCatalog;
use LumoraFiction\Core\Plugin\PluginInfo;
use LumoraFiction\Core\Plugin\PluginLoadFailure;
use LumoraFiction\Core\Plugin\PluginLoader;
use LumoraFiction\Models\User;
use Throwable;

/**
 * A plugin that fails to load stays in the active list, so a fix to its files brings it back on the next request,
 * but it is skipped, noted once in the error log and the audit log, and kept in a failure list the dashboard reads.
 * Writing the notes only when the message changes keeps a broken plugin from filling the log on every page view.
 */
final class PluginManager
{
    public const DEFAULTS = ['plugins.active' => [], 'plugins.failures' => []];

    public function __construct(
        private readonly Configuration $config,
        private readonly PluginCatalog $catalog,
        private readonly PluginLoader $loader,
        private readonly AuditLogService $audit,
        private readonly HookManager $hooks,
        private readonly ErrorHandler $errors,
        private readonly string $appVersion,
    ) {
    }

    /**
     * @return list<string> the slugs an administrator has turned on
     */
    public function activeSlugs(): array
    {
        $stored = $this->config->setting('plugins.active', []);
        $slugs = [];

        // A hand-edited setting must not be able to name anything but a plain slug.
        foreach (is_array($stored) ? $stored : [] as $slug) {
            if (is_string($slug) && PluginCatalog::isValidSlug($slug) && !in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * @return array<string, string> slug => why it was skipped on its last load
     */
    public function failures(): array
    {
        $stored = $this->config->setting('plugins.failures', []);
        $failures = [];

        foreach (is_array($stored) ? $stored : [] as $slug => $message) {
            if (is_string($slug) && is_string($message) && PluginCatalog::isValidSlug($slug)) {
                $failures[$slug] = $message;
            }
        }

        return $failures;
    }

    public function catalog(): PluginCatalog
    {
        return $this->catalog;
    }

    /**
     * Runs every active plugin. Called once while the application starts.
     */
    public function loadActive(): void
    {
        $failures = $this->failures();
        $changed = false;

        foreach ($this->activeSlugs() as $slug) {
            $failure = $this->loader->load($slug);

            if ($failure === null) {
                if (isset($failures[$slug])) {
                    unset($failures[$slug]);
                    $changed = true;
                }

                continue;
            }

            if (($failures[$slug] ?? null) !== $failure->message) {
                $failures[$slug] = $failure->message;
                $changed = true;
                $this->noteFailure($slug, $failure);
            }
        }

        if ($changed) {
            $this->saveFailures($failures);
        }
    }

    /**
     * Turns a plugin on. The plugin is run once first, so one that cannot start is refused instead of being
     * switched on and skipped. Returns a message safe to show, or null when it is now active.
     */
    public function activate(string $slug, User $actor, string $ipAddress): ?string
    {
        $info = $this->catalog->find($slug);

        if ($info === null) {
            return 'That plugin is not available.';
        }

        if (in_array($slug, $this->activeSlugs(), true)) {
            return null;
        }

        $failure = $this->loader->load($slug);

        if ($failure !== null) {
            if ($failure->exception !== null) {
                $this->errors->report($failure->exception);
            }

            return 'The plugin was not turned on: ' . $failure->message;
        }

        $this->config->setSetting('plugins.active', [...$this->activeSlugs(), $slug]);
        $this->forget($slug);
        $this->audit->record($actor->id, 'plugin.activate', 'plugin', $slug, ['version' => $info->version], $ipAddress);

        $this->hooks->doAction('plugin_activated', $slug);

        return null;
    }

    /**
     * Turns a plugin off, also when its folder has gone, so a stale entry can always be cleared. Returns false when
     * it was not active.
     */
    public function deactivate(string $slug, User $actor, string $ipAddress): bool
    {
        $active = $this->activeSlugs();

        if (!in_array($slug, $active, true)) {
            return false;
        }

        $this->config->setSetting('plugins.active', array_values(array_diff($active, [$slug])));
        $this->forget($slug);
        $this->audit->record($actor->id, 'plugin.deactivate', 'plugin', $slug, [], $ipAddress);

        $this->hooks->doAction('plugin_deactivated', $slug);

        return true;
    }

    /**
     * Every plugin the screen should show: the installed ones, and any active one whose folder is gone.
     *
     * @return list<array{slug: string, info: PluginInfo|null, active: bool, failure: string, problems: list<string>}>
     */
    public function listing(): array
    {
        $installed = $this->catalog->installed();
        $active = $this->activeSlugs();
        $failures = $this->failures();
        $rows = [];

        foreach ($installed as $slug => $info) {
            $rows[] = [
                'slug' => $slug,
                'info' => $info,
                'active' => in_array($slug, $active, true),
                'failure' => $failures[$slug] ?? '',
                'problems' => $info->unmetRequirements(PHP_VERSION, $this->appVersion),
            ];
        }

        foreach ($active as $slug) {
            if (!isset($installed[$slug])) {
                $rows[] = ['slug' => $slug, 'info' => null, 'active' => true, 'failure' => $failures[$slug] ?? '', 'problems' => []];
            }
        }

        return $rows;
    }

    /**
     * A failure to write the notes must not take the site down on top of the plugin that already failed.
     */
    private function noteFailure(string $slug, PluginLoadFailure $failure): void
    {
        try {
            if ($failure->exception !== null) {
                $this->errors->report($failure->exception);
            }

            $this->audit->record(null, 'plugin.load_failed', 'plugin', $slug, ['message' => $failure->message]);
        } catch (Throwable $exception) {
            $this->errors->report($exception);
        }
    }

    private function forget(string $slug): void
    {
        $failures = $this->failures();

        if (isset($failures[$slug])) {
            unset($failures[$slug]);
            $this->saveFailures($failures);
        }
    }

    /**
     * @param array<string, string> $failures
     */
    private function saveFailures(array $failures): void
    {
        try {
            $this->config->setSetting('plugins.failures', $failures);
        } catch (Throwable $exception) {
            $this->errors->report($exception);
        }
    }
}
