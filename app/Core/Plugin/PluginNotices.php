<?php

/**
 * Tells the administrator's dashboard which plugins were skipped.
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
use LumoraFiction\Services\PluginManager;

final class PluginNotices
{
    public function __construct(private readonly PluginManager $plugins)
    {
    }

    public function register(HookManager $hooks): void
    {
        $hooks->addFilter('admin_dashboard_notices', $this->add(...), 20);
    }

    /**
     * @param mixed $notices the notices collected so far
     * @return list<array{variant: string, message: string}>
     */
    public function add(mixed $notices): array
    {
        $notices = is_array($notices) ? array_values($notices) : [];

        foreach ($this->plugins->failures() as $slug => $message) {
            $name = $this->plugins->catalog()->find($slug)?->name ?? $slug;
            $notices[] = [
                'variant' => 'error',
                'message' => sprintf('The plugin "%s" could not be loaded and was skipped: %s Open Plugins under Maintenance to turn it off.', $name, $message),
            ];
        }

        return $notices;
    }
}
