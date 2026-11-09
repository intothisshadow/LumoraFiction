<?php

/**
 * Whether the site asks search engines to stay away, and whether it publishes a sitemap.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;

/**
 * Discouraging search engines is a request, not a lock: robots.txt and a noindex tag ask well-behaved crawlers to leave,
 * and say nothing about who may read the pages. The readers are static so the head, robots.txt and the sitemap can ask
 * without holding a settings object; a damaged value falls back to the default (listed) rather than hiding the site.
 */
final class SearchSettings
{
    public const DEFAULTS = ['seo.discourage' => false];

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    /** Only a real "true" asks search engines to stay away. */
    public static function discouraged(Configuration $config): bool
    {
        return $config->setting('seo.discourage', false) === true;
    }

    /**
     * Whether the sitemap is published. A site that asks crawlers to stay away has no use for one, and an archive that
     * requires sign-in would be listing pages a crawler cannot open.
     */
    public static function sitemapOffered(Configuration $config): bool
    {
        return !self::discouraged($config) && $config->setting('reading.require_signin') !== true;
    }

    /**
     * @return array{discourage: bool}
     */
    public function current(): array
    {
        return ['discourage' => self::discouraged($this->config)];
    }

    /**
     * @param array{discourage: bool} $input
     * @return list<string> the names of the settings that changed
     */
    public function save(array $input, User $actor, string $ipAddress): array
    {
        if (!$actor->can(Capability::ManageSite)) {
            return [];
        }

        if ($input['discourage'] === self::discouraged($this->config)) {
            return [];
        }

        $change = ['seo.discourage' => ['from' => self::discouraged($this->config), 'to' => $input['discourage']]];

        $this->database->transaction(function () use ($input, $change, $actor, $ipAddress): void {
            $this->config->setSetting('seo.discourage', $input['discourage']);
            $this->audit->record($actor->id, 'settings.search.update', 'settings', 'search', $change, $ipAddress);
        });

        return ['seo.discourage'];
    }
}
