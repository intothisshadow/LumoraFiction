<?php

/**
 * Page caching settings: the switch, which kinds of page, how long, which paths never, and which outside cache to talk to.
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

use LumoraFiction\Core\Cache\CacheExclusions;
use LumoraFiction\Core\Cache\PageCache;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;

/**
 * A damaged stored value falls back to the default, so a bad setting cannot make pages stay stale for a year. Browsers
 * default to checking with the site on every visit (a lifetime of 0), which costs a small `304` and means a change is
 * never hidden by a browser; only the shared cache is given a long lifetime, and a purge empties that.
 */
final class CacheSettings
{
    public const DEFAULT_LIFETIME = 3600;

    public const MAX_LIFETIME = 2592000;

    public const DEFAULTS = [
        'cache.enabled' => true,
        'cache.types' => ['home', 'stories', 'listings', 'news', 'pages', 'search'],
        'cache.lifetime' => self::DEFAULT_LIFETIME,
        'cache.browser_lifetime' => 0,
        'cache.exclusions' => '',
        'cache.driver' => PageCache::DRIVER_AUTO,
    ];

    /** @var array<string, string> */
    public const DRIVERS = [
        PageCache::DRIVER_AUTO => 'Detect automatically',
        PageCache::DRIVER_LITESPEED => 'LiteSpeed Cache',
        PageCache::DRIVER_NONE => 'None',
    ];

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * @return array{enabled: bool, types: list<string>, lifetime: int, browser_lifetime: int, exclusions: string, driver: string}
     */
    public function current(): array
    {
        $types = $this->config->setting('cache.types');
        $lifetime = $this->config->setting('cache.lifetime');
        $browser = $this->config->setting('cache.browser_lifetime');
        $exclusions = $this->config->setting('cache.exclusions');
        $driver = $this->config->setting('cache.driver');

        $lifetime = is_int($lifetime) && $lifetime >= 0 && $lifetime <= self::MAX_LIFETIME ? $lifetime : self::DEFAULT_LIFETIME;

        return [
            'enabled' => $this->config->setting('cache.enabled') !== false,
            'types' => is_array($types) ? array_values(array_filter(array_keys(PageCache::KINDS), static fn (string $kind): bool => in_array($kind, $types, true))) : array_keys(PageCache::KINDS),
            'lifetime' => $lifetime,
            'browser_lifetime' => is_int($browser) && $browser >= 0 && $browser <= $lifetime ? $browser : 0,
            'exclusions' => is_string($exclusions) && CacheExclusions::problem($exclusions) === null ? $exclusions : '',
            'driver' => is_string($driver) && isset(self::DRIVERS[$driver]) ? $driver : PageCache::DRIVER_AUTO,
        ];
    }

    /**
     * @param array{enabled?: bool, types?: list<string>, lifetime?: ?string, browser_lifetime?: ?string, exclusions?: ?string, driver?: ?string} $input
     * @return array{errors: array<string, string>, values: array{enabled: bool, types: list<string>, lifetime: int, browser_lifetime: int, exclusions: string, driver: string}}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $current = $this->current();

        $enabled = array_key_exists('enabled', $input) ? $input['enabled'] === true : $current['enabled'];
        $ticked = array_key_exists('types', $input) ? $input['types'] : $current['types'];
        $types = array_values(array_filter(array_keys(PageCache::KINDS), static fn (string $kind): bool => in_array($kind, $ticked, true)));

        $lifetime = $this->whole($input['lifetime'] ?? null);

        if ($lifetime === null || $lifetime > self::MAX_LIFETIME) {
            $errors['lifetime'] = 'Enter a number of seconds from 0 to ' . self::MAX_LIFETIME . '.';
            $lifetime = $current['lifetime'];
        }

        $browser = $this->whole($input['browser_lifetime'] ?? null);

        if ($browser === null) {
            $errors['browser_lifetime'] = 'Enter a number of seconds, or 0 to have browsers check every time.';
            $browser = $current['browser_lifetime'];
        } elseif ($browser > $lifetime) {
            $errors['browser_lifetime'] = 'Keep the browser lifetime at or below the lifetime above, or a purge would not reach visitors for the difference.';
            $browser = min($current['browser_lifetime'], $lifetime);
        }

        $exclusions = str_replace("\r\n", "\n", trim((string) ($input['exclusions'] ?? '')));
        $problem = CacheExclusions::problem($exclusions);

        if ($problem !== null) {
            $errors['exclusions'] = $problem;
            $exclusions = $current['exclusions'];
        }

        $driver = (string) ($input['driver'] ?? $current['driver']);

        if (!isset(self::DRIVERS[$driver])) {
            $errors['driver'] = 'Choose one of the listed caches.';
            $driver = $current['driver'];
        }

        return ['errors' => $errors, 'values' => ['enabled' => $enabled, 'types' => $types, 'lifetime' => $lifetime, 'browser_lifetime' => $browser, 'exclusions' => $exclusions, 'driver' => $driver]];
    }

    /**
     * @param array{enabled: bool, types: list<string>, lifetime: int, browser_lifetime: int, exclusions: string, driver: string} $values
     * @return list<string> the names of the settings that changed
     */
    public function save(array $values, User $actor, string $ipAddress): array
    {
        if (!$actor->can(Capability::ManageSite)) {
            return [];
        }

        $current = $this->current();
        $changes = [];

        foreach ($current as $field => $was) {
            if ($values[$field] !== $was) {
                $changes['cache.' . $field] = ['from' => $was, 'to' => $values[$field]];
            }
        }

        if ($changes === []) {
            return [];
        }

        $this->database->transaction(function () use ($changes, $actor, $ipAddress): void {
            foreach ($changes as $setting => $change) {
                $this->config->setSetting($setting, $change['to']);
            }

            $this->audit->record($actor->id, 'settings.cache.update', 'settings', 'cache', $changes, $ipAddress);
        });

        return array_keys($changes);
    }

    /**
     * Plain-language problems with the settings as saved. Advisory only; nothing here blocks saving.
     *
     * @param array{enabled: bool, types: list<string>, lifetime: int, browser_lifetime: int, exclusions: string, driver: string} $values
     * @return list<string>
     */
    public function warnings(array $values, bool $liteSpeedServer, bool $visitorChoice, bool $signInRequired, bool $viewsCounted): array
    {
        $warnings = [];

        if ($values['enabled'] && $values['lifetime'] === 0) {
            $warnings[] = 'The lifetime is 0, so pages are marked as stale straight away and a shared cache keeps nothing.';
        }

        if ($values['enabled'] && $values['types'] === []) {
            $warnings[] = 'Caching is on, but every kind of page is switched off, so nothing is cached.';
        }

        if ($values['driver'] === PageCache::DRIVER_LITESPEED && !$liteSpeedServer) {
            $warnings[] = 'The cache is set to LiteSpeed, but this server does not say it is LiteSpeed. The instructions are sent and ignored unless a LiteSpeed cache sits in front of the site. Use automatic detection otherwise.';
        }

        if ($values['enabled'] && $signInRequired) {
            $warnings[] = 'Reading needs a signed-in account (Settings > Archive), so no page is cached.';
        }

        if ($values['enabled'] && $visitorChoice) {
            $warnings[] = 'Visitors can choose their own theme (Appearance > Themes), so pages are kept by each visitor\'s own browser only and a shared cache keeps none of them.';
        }

        if ($values['enabled'] && $viewsCounted) {
            $warnings[] = 'A plugin counts story views, so story and chapter pages are kept by the browser only, after checking with the site, and a shared cache keeps none of them. That keeps the counts right.';
        }

        if (!$values['enabled'] && $values['driver'] !== PageCache::DRIVER_NONE) {
            $warnings[] = 'Caching is off, but an outside cache may still hold pages from before. Changes still empty it.';
        }

        return $warnings;
    }

    private function whole(?string $value): ?int
    {
        $value = trim((string) $value);

        return preg_match('/^\d{1,8}\z/', $value) === 1 ? (int) $value : null;
    }
}
