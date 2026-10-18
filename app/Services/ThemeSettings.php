<?php

/**
 * Which theme the site uses.
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
use LumoraFiction\Core\Theme\ThemeCatalog;
use LumoraFiction\Models\User;

/**
 * An empty choice means nobody has picked a theme, so the site uses the default one when it is installed and the
 * built-in theme when it is not. A chosen slug is kept even when that theme later disappears, so putting the
 * folder back restores the site; until then the renderer quietly uses the built-in theme.
 */
final class ThemeSettings
{
    public const DEFAULTS = ['theme.active' => ''];

    public function __construct(
        private readonly Configuration $config,
        private readonly ThemeCatalog $catalog,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * The slug an administrator picked, or an empty string when nobody has.
     */
    public function chosenSlug(): string
    {
        $slug = $this->config->setting('theme.active', '');

        return is_string($slug) ? $slug : '';
    }

    /**
     * False only when a theme was picked and is no longer installed or valid, with the built-in theme standing in for it.
     */
    public function chosenIsAvailable(): bool
    {
        $slug = $this->chosenSlug();

        return $slug === '' || $this->catalog->find($slug) !== null;
    }

    /**
     * Returns false, and changes nothing, when the slug is not an installed, valid theme.
     */
    public function activate(string $slug, User $actor, string $ipAddress): bool
    {
        if ($this->catalog->find($slug) === null) {
            return false;
        }

        $previous = $this->chosenSlug();

        if ($previous !== $slug) {
            $this->config->setSetting('theme.active', $slug);
            $this->audit->record($actor->id, 'settings.theme.activate', 'theme', $slug, ['from' => $previous, 'to' => $slug], $ipAddress);
        }

        return true;
    }
}
