<?php

/**
 * Decides whether a page view counts and, if so, records it.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\VisitorStats;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Models\Chapter;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\User;
use PDOException;

final class Tracker
{
    public const SETTING_TRACK = 'visitor_stats.track';

    private const SETTING_SCHEMA = 'visitor_stats.schema';

    public function __construct(
        private readonly Configuration $config,
        private readonly Store $store,
        private readonly GeoIp $geo,
        private readonly UserAgentParser $agents,
    ) {
    }

    /** Turned on by an administrator and off until then, so activating the plugin collects nothing by itself. */
    public static function isOn(Configuration $config): bool
    {
        return $config->setting(self::SETTING_TRACK, false) === true;
    }

    public function enabled(): bool
    {
        return self::isOn($this->config);
    }

    public function ensureTables(): void
    {
        if ($this->config->setting(self::SETTING_SCHEMA, 0) !== 1) {
            $this->store->ensureTables();
            $this->config->setSetting(self::SETTING_SCHEMA, 1);
        }
    }

    /**
     * Listens to `story_viewed`, which core fires only for a public story page that is about to be shown, so a draft,
     * a pending story and a private preview never reach this. Only the rules about who is looking live here.
     */
    public function handle(Story $story, ?Chapter $chapter, ?User $viewer, Request $request): void
    {
        if (!self::isOn($this->config) || $viewer !== null || $request->method !== 'GET' || $this->isPrefetch($request)) {
            return;
        }

        $agent = (string) ($request->server['HTTP_USER_AGENT'] ?? '');

        if ($this->agents->isBot($agent)) {
            return;
        }

        $breakdown = [
            Store::BREAKDOWN_BROWSER => $this->agents->browser($agent),
            Store::BREAKDOWN_DEVICE => $this->agents->deviceType($agent),
        ];

        $referrer = $this->externalReferrer((string) ($request->server['HTTP_REFERER'] ?? ''), $request);

        if ($referrer !== null) {
            $breakdown[Store::BREAKDOWN_REFERRER] = $referrer;
        }

        // The address is only used to find a country and is dropped here; it is never written anywhere.
        $country = $this->geo->countryFor($request->ip());

        if ($country !== null) {
            $breakdown[Store::BREAKDOWN_COUNTRY] = $country;
        }

        try {
            $this->ensureTables();
            $this->store->recordView($story->id, $breakdown);
        } catch (PDOException $exception) {
            // Counting is never worth an error page for a reader.
            error_log('Visitor Stats could not record a view: ' . $exception->getMessage());
        }
    }

    /** A browser warming a link up in the background has not shown the page to anyone. */
    private function isPrefetch(Request $request): bool
    {
        foreach (['HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_MOZ'] as $key) {
            if (stripos((string) ($request->server[$key] ?? ''), 'prefetch') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The domain a visitor came from, or null for a direct visit, an unreadable address or a click from this site.
     * Only the host is kept; the path and query of the page they came from are dropped here.
     */
    private function externalReferrer(string $referrer, Request $request): ?string
    {
        $host = parse_url($referrer, PHP_URL_HOST);

        if (!is_string($host) || preg_match('/^[a-z0-9.-]{1,100}\z/i', $host) !== 1) {
            return null;
        }

        $host = strtolower(preg_replace('/^www\./i', '', $host) ?? $host);
        $own = parse_url((string) $this->config->get('site_url', ''), PHP_URL_HOST);
        $own = is_string($own) && $own !== '' ? $own : (string) ($request->server['HTTP_HOST'] ?? '');
        $own = strtolower(preg_replace(['/^www\./i', '/:\d+\z/'], '', $own) ?? $own);

        return $host === $own ? null : $host;
    }
}
