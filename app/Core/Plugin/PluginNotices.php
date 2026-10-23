<?php

/**
 * Raises an alert for each plugin that was skipped.
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

use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Models\AlertSeverity;
use LumoraFiction\Models\SiteAlert;
use LumoraFiction\Services\PluginManager;

final class PluginNotices
{
    public function __construct(private readonly PluginManager $plugins)
    {
    }

    public function register(HookManager $hooks): void
    {
        $hooks->addFilter('admin_alerts', $this->add(...), 20);
    }

    /**
     * @param mixed $alerts the alerts collected so far
     * @return list<mixed>
     */
    public function add(mixed $alerts): array
    {
        $alerts = is_array($alerts) ? array_values($alerts) : [];

        foreach ($this->plugins->failures() as $slug => $message) {
            $name = $this->plugins->catalog()->find($slug)?->name ?? $slug;
            // Hashed, so a folder name that is not a valid key can still raise its alert.
            $alerts[] = new SiteAlert(
                'plugin.failed.' . substr(sha1((string) $slug), 0, 12),
                AlertSeverity::Critical,
                sprintf('The plugin "%s" could not be loaded and was skipped: %s Open Plugins under Maintenance to turn it off.', $name, $message),
                '',
                'maintenance/plugins',
            );
        }

        return $alerts;
    }
}
