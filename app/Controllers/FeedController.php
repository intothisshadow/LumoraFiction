<?php

/**
 * Serves the feeds as RSS or JSON Feed, with conditional responses.
 *
 * @package LumoraFiction
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers;

use Closure;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Feed\Feed;
use LumoraFiction\Core\Feed\JsonFeedWriter;
use LumoraFiction\Core\Feed\RssWriter;
use LumoraFiction\Core\Http\EntityTag;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Http\Router;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Services\FeedService;
use LumoraFiction\Services\FeedSettings;

/**
 * A feed is the same for everyone and is never drawn in a theme or tied to a cookie, so a cache may keep one for any
 * visitor. The JSON form of a feed is its address with ".json" added. Slugs only ever hold letters, digits and hyphens,
 * so the suffix can never be part of a real name.
 */
final class FeedController
{
    private const MAX_AGE = 300;

    private const JSON_SUFFIX = '.json';

    public function __construct(
        private readonly FeedService $feeds,
        private readonly ThemeRenderer $themes,
        private readonly Configuration $config,
    ) {
    }

    /**
     * Fixed words come before a placeholder at the same depth, so a story or term slug can never be read as one.
     */
    public function register(Router $router): void
    {
        $router->get('/feed', fn (array $params, Request $request): Response => $this->serve($request, false, fn (): ?Feed => $this->feeds->site()), 'feed.site');
        $router->get('/feed.json', fn (array $params, Request $request): Response => $this->serve($request, true, fn (): ?Feed => $this->feeds->site()), 'feed.site.json');
        $router->get('/feed/news', fn (array $params, Request $request): Response => $this->serve($request, false, fn (): ?Feed => $this->feeds->news()), 'feed.news');
        $router->get('/feed/news.json', fn (array $params, Request $request): Response => $this->serve($request, true, fn (): ?Feed => $this->feeds->news()), 'feed.news.json');

        $router->get('/feed/author/{slug}', fn (array $params, Request $request): Response => $this->named($params, $request, fn (string $slug): ?Feed => $this->feeds->author($slug)), 'feed.author');

        foreach (FeedService::termKinds() as $type) {
            $router->get('/feed/' . $type->value . '/{slug}', fn (array $params, Request $request): Response => $this->named($params, $request, fn (string $slug): ?Feed => $this->feeds->term($type, $slug)), 'feed.' . $type->value);
        }

        $router->get('/feed/story/{slug}/comments', fn (array $params, Request $request): Response => $this->serve($request, false, fn (): ?Feed => $this->feeds->storyComments($params['slug'] ?? '')), 'feed.story.comments');
        $router->get('/feed/story/{slug}/comments.json', fn (array $params, Request $request): Response => $this->serve($request, true, fn (): ?Feed => $this->feeds->storyComments($params['slug'] ?? '')), 'feed.story.comments.json');
        $router->get('/feed/story/{slug}', fn (array $params, Request $request): Response => $this->named($params, $request, fn (string $slug): ?Feed => $this->feeds->story($slug)), 'feed.story');
    }

    /**
     * A feed whose last address segment is a slug, with or without the JSON suffix.
     *
     * @param array<string, string> $params
     * @param Closure(string): ?Feed $build
     */
    private function named(array $params, Request $request, Closure $build): Response
    {
        $slug = $params['slug'] ?? '';
        $json = str_ends_with($slug, self::JSON_SUFFIX);

        if ($json) {
            $slug = substr($slug, 0, -strlen(self::JSON_SUFFIX));
        }

        return $this->serve($request, $json, static fn (): ?Feed => $build($slug));
    }

    /**
     * @param Closure(): ?Feed $build
     */
    private function serve(Request $request, bool $json, Closure $build): Response
    {
        // Checked before anything is built, so a switched-off form costs nothing.
        $feed = $json && !FeedSettings::jsonEnabled($this->config) ? null : $build();

        if ($feed === null) {
            return $this->themes->notFound();
        }

        $body = $json ? JsonFeedWriter::write($feed) : RssWriter::write($feed);
        $etag = EntityTag::of($body);
        $headers = [
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=' . self::MAX_AGE,
            // A feed is for readers, not for search engines.
            'X-Robots-Tag' => 'noindex',
        ];

        if (EntityTag::matches($request, $etag)) {
            return new Response('', 304, $headers);
        }

        return new Response($body, 200, ['Content-Type' => $json ? 'application/feed+json; charset=utf-8' : 'application/rss+xml; charset=utf-8'] + $headers);
    }
}
