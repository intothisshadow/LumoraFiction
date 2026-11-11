<?php

/**
 * Cache headers and conditional answers for public pages, and the instructions that tell an outside cache to forget them.
 *
 * @package LumoraFiction
 * @subpackage Cache
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Cache;

use Closure;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Http\BasePath;
use LumoraFiction\Core\Http\EntityTag;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Http\Router;
use LumoraFiction\Core\Security\SessionManager;
use LumoraFiction\Services\CacheSettings;

/**
 * Lumora Fiction keeps no page cache of its own. It sends the headers that let a browser or a shared cache reuse a page,
 * answers `304 Not Modified` when the visitor already holds the page, and tells LiteSpeed which pages to drop when
 * something changes. A page is cacheable only when it is the same for every visitor: nothing for a person with a session,
 * nothing that sets a cookie, nothing whose handler chose its own Cache-Control, and nothing for a kind of page or a path
 * the administrator switched off. The page is always drawn first, so a view counter still hears `story_viewed` on a 304.
 */
final class PageCache
{
    public const DRIVER_AUTO = 'auto';

    public const DRIVER_LITESPEED = 'litespeed';

    public const DRIVER_NONE = 'none';

    /** @var array<string, string> kind => what it covers, as shown to the administrator */
    public const KINDS = [
        'home' => 'The front page',
        'stories' => 'Story and chapter pages',
        'listings' => 'Browse, author, fandom, character, relationship, genre, tag, category, series and collection pages',
        'news' => 'News pages',
        'pages' => 'Static pages',
        'search' => 'Search results',
    ];

    private const PURGE_LOG_LIMIT = 20;

    /** @var array<string, true> */
    private array $pendingTags = [];

    private bool $pendingAll = false;

    /**
     * @param Closure(): bool $signInRequired whether reading needs a signed-in account
     * @param Closure(): bool $visitorChoice whether visitors may pick their own theme, which makes a page differ by cookie
     * @param string $logPath where the recent purges are noted, so the screen can show them
     */
    public function __construct(
        private readonly CacheSettings $settings,
        private readonly HookManager $hooks,
        private readonly Closure $signInRequired,
        private readonly Closure $visitorChoice,
        private readonly string $logPath,
    ) {
    }

    public function settings(): CacheSettings
    {
        return $this->settings;
    }

    /**
     * The kind of page a route is, or null for a route that is never cached (accounts, downloads, the theme styles).
     */
    public static function kindOf(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }

        return match (true) {
            $routeName === 'home' => 'home',
            $routeName === 'story' || $routeName === 'chapter' => 'stories',
            $routeName === 'search' => 'search',
            $routeName === 'news' || $routeName === 'news.post' => 'news',
            $routeName === Router::FALLBACK_NAME => 'pages',
            in_array($routeName, ['browse', 'browse.page', 'author', 'series', 'collections', 'collection'], true), str_starts_with($routeName, 'term.') => 'listings',
            default => null,
        };
    }

    /**
     * Adds the cache headers to a public page, or the answer that nothing has changed, and carries any purge the request
     * caused. Anything that is not a plain public page passes through with at most a purge.
     *
     * @param array<string, string> $params the route's parameters
     */
    public function apply(Request $request, Response $response, ?string $routeName, array $params = []): Response
    {
        if (in_array($request->method, ['GET', 'HEAD'], true)) {
            $response = $this->decorate($request, $response, $routeName, $params);
        }

        return $this->withPurge($request, $response);
    }

    /**
     * @param array<string, string> $params
     */
    private function decorate(Request $request, Response $response, ?string $routeName, array $params): Response
    {
        $contentType = (string) $response->header('Content-Type');

        // Styles, scripts, feeds and downloads manage their own headers.
        if (!str_starts_with(strtolower($contentType), 'text/html') || $response->header('Cache-Control') !== null) {
            return $response;
        }

        $policy = $this->cacheable($request, $response, $routeName, $params);

        if ($policy === null) {
            return $response->withHeader('Cache-Control', 'private, no-store');
        }

        $etag = EntityTag::of($response->body);
        $lifetime = $policy['lifetime'];
        $browser = $policy['browser'];

        $headers = [
            'Cache-Control' => $policy['shared']
                ? 'public, max-age=' . $browser . ($browser !== $lifetime ? ', s-maxage=' . $lifetime : '')
                : ($browser > 0 ? 'private, max-age=' . $browser : 'private, no-cache'),
            'ETag' => $etag,
            'Vary' => $this->vary((string) $response->header('Vary')),
        ];

        if ($policy['shared'] && $this->driverFor($request) === self::DRIVER_LITESPEED && $policy['tags'] !== []) {
            $headers['X-LiteSpeed-Tag'] = implode(',', $policy['tags']);
            $headers['X-LiteSpeed-Cache-Control'] = 'public,max-age=' . $lifetime;
        }

        if (EntityTag::matches($request, $etag)) {
            // Only the validators and lifetime are repeated; the visitor already holds the body and its type.
            return new Response('', 304, array_diff_key($headers, ['X-LiteSpeed-Tag' => true, 'X-LiteSpeed-Cache-Control' => true]));
        }

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * @param array<string, string> $params
     * @return array{shared: bool, lifetime: int, browser: int, tags: list<string>}|null null when the page must not be reused
     */
    private function cacheable(Request $request, Response $response, ?string $routeName, array $params): ?array
    {
        $settings = $this->settings->current();
        $kind = self::kindOf($routeName);

        if (
            $kind === null || $response->status !== 200 || !$settings['enabled'] || !in_array($kind, $settings['types'], true)
            || $response->header('Set-Cookie') !== null || $this->cookieBeingSet()
            // A person with a session may be signed in, and a signed-in page carries their menu and their forms.
            || isset($request->cookies[SessionManager::DEFAULT_NAME])
            || ($this->signInRequired)()
            || CacheExclusions::matches((string) parse_url(BasePath::stripFrom($request->uri), PHP_URL_PATH), $settings['exclusions'])
        ) {
            return null;
        }

        // The visitor's theme choice makes the page differ by cookie, and its switch carries a token tied to the visitor's
        // address, so only that visitor's own browser may keep it. Pages a plugin counts the views of must reach PHP on
        // every visit, so they are kept only by the browser, and only after checking with the site.
        $counted = $kind === 'stories' && $this->hooks->hasAction('story_viewed');
        $shared = !($this->visitorChoice)() && !$counted && $response->header('Vary') === null;

        return [
            'shared' => $shared,
            'lifetime' => $settings['lifetime'],
            'browser' => $counted ? 0 : min($settings['browser_lifetime'], $settings['lifetime']),
            'tags' => $this->tagsFor($kind, $params),
        ];
    }

    /**
     * @param array<string, string> $params
     * @return list<string>
     */
    private function tagsFor(string $kind, array $params): array
    {
        return match ($kind) {
            'stories' => isset($params['slug']) ? [PurgeRules::storyTag($params['slug'])] : [],
            'news' => [PurgeRules::NEWS],
            'pages' => isset($params['slug']) ? [PurgeRules::pageTag($params['slug'])] : [],
            default => [PurgeRules::LISTINGS],
        };
    }

    private function vary(string $existing): string
    {
        if ($existing === '') {
            return 'Cookie';
        }

        return stripos($existing, 'cookie') === false ? $existing . ', Cookie' : $existing;
    }

    /** A cookie PHP has already queued for this response (a session started by a form on the page, say). */
    private function cookieBeingSet(): bool
    {
        foreach (headers_list() as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) {
                return true;
            }
        }

        return false;
    }

    // ---- telling an outside cache to forget ---------------------------------------------------------------

    /**
     * The driver in use for this request: LiteSpeed when it is chosen, or when the server announces itself as LiteSpeed
     * and the choice is automatic.
     */
    public function driverFor(Request $request): string
    {
        $choice = $this->settings->current()['driver'];

        if ($choice === self::DRIVER_AUTO) {
            return self::liteSpeedDetected($request->server) ? self::DRIVER_LITESPEED : self::DRIVER_NONE;
        }

        return $choice === self::DRIVER_LITESPEED ? self::DRIVER_LITESPEED : self::DRIVER_NONE;
    }

    /**
     * @param array<string, mixed> $server
     */
    public static function liteSpeedDetected(array $server): bool
    {
        // Permissive on purpose: a false positive sends a header LiteSpeed ignores, a false negative would drop real purges.
        return str_contains(strtolower((string) ($server['SERVER_SOFTWARE'] ?? '')), 'litespeed') || isset($server['HTTP_X_LSCACHE']);
    }

    /**
     * Asks for pages to be dropped when this request's answer goes out.
     *
     * @param list<string> $scope tags, or [PurgeRules::ALL]
     */
    public function purge(array $scope): void
    {
        foreach ($scope as $tag) {
            if ($tag === PurgeRules::ALL) {
                $this->pendingAll = true;
            } else {
                $this->pendingTags[$tag] = true;
            }
        }
    }

    /**
     * What this request has asked to be dropped: 'all', or tags.
     *
     * @return list<string>
     */
    public function pending(): array
    {
        return $this->pendingAll ? [PurgeRules::ALL] : array_keys($this->pendingTags);
    }

    private function withPurge(Request $request, Response $response): Response
    {
        $pending = $this->pending();

        // What was asked for is handed over once, with the answer it was asked during.
        $this->pendingAll = false;
        $this->pendingTags = [];

        if ($pending === [] || $this->driverFor($request) !== self::DRIVER_LITESPEED) {
            return $response;
        }

        $this->note($pending);

        // One header with every instruction: the response can carry a header name only once.
        return $response->withHeader('X-LiteSpeed-Purge', $pending === [PurgeRules::ALL] ? '*' : implode(', ', array_map(static fn (string $tag): string => 'tag=' . $tag, $pending)));
    }

    /**
     * The most recent purges, newest first, for the Cache screen.
     *
     * @return list<array{what: string, at: string}>
     */
    public function recentPurges(): array
    {
        if (!is_file($this->logPath)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->logPath), true);

        return is_array($decoded) ? array_values(array_filter($decoded, static fn (mixed $entry): bool => is_array($entry) && is_string($entry['what'] ?? null) && is_string($entry['at'] ?? null))) : [];
    }

    /**
     * @param list<string> $pending
     */
    private function note(array $pending): void
    {
        $entries = $this->recentPurges();
        array_unshift($entries, ['what' => $pending === [PurgeRules::ALL] ? 'Every page' : implode(', ', $pending), 'at' => gmdate('Y-m-d H:i:s')]);

        $directory = dirname($this->logPath);

        // The note is only a convenience; a folder that cannot be written must not break the page.
        if ((is_dir($directory) && is_writable($directory)) || (!is_dir($directory) && is_writable(dirname($directory)) && mkdir($directory, 0755, true))) {
            file_put_contents($this->logPath, json_encode(array_slice($entries, 0, self::PURGE_LOG_LIMIT)), LOCK_EX);
        }
    }
}
