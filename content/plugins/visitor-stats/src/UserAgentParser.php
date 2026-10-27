<?php

/**
 * Coarse browser, device and crawler classification from a User-Agent string.
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

/**
 * Callers only ever get one of a few fixed labels back, never the string itself, which is why the plugin can
 * promise that no User-Agent is stored.
 */
final class UserAgentParser
{
    /** An empty User-Agent is treated as a crawler: real browsers always send one. */
    public function isBot(string $userAgent): bool
    {
        return $userAgent === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|bingpreview|headless|python-requests|curl\/|wget|libwww|httpclient|monitor|feedfetcher|lighthouse/i', $userAgent) === 1;
    }

    /** Chromium browsers also say "Safari", so Safari has to be tested last. */
    public function browser(string $userAgent): string
    {
        return match (true) {
            str_contains($userAgent, 'Edg/'), str_contains($userAgent, 'Edge/') => 'Edge',
            str_contains($userAgent, 'OPR/'), str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox/'), str_contains($userAgent, 'FxiOS/') => 'Firefox',
            str_contains($userAgent, 'Chrome/'), str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') && str_contains($userAgent, 'Version/') => 'Safari',
            default => 'Other',
        };
    }

    public function deviceType(string $userAgent): string
    {
        return match (true) {
            str_contains($userAgent, 'iPad'), str_contains($userAgent, 'Android') && !str_contains($userAgent, 'Mobile') => 'Tablet',
            str_contains($userAgent, 'Mobi'), str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'Android') => 'Mobile',
            default => 'Desktop',
        };
    }
}
