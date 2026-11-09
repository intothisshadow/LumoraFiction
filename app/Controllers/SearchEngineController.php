<?php

/**
 * The sitemap and the virtual robots.txt.
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

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Http\BasePath;
use LumoraFiction\Core\Http\EntityTag;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Http\Router;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Services\SearchSettings;
use LumoraFiction\Services\SitemapService;

/**
 * Both are the same for every visitor and need no cookie, so a cache may keep them. A real robots.txt file in the site's
 * folder is answered by the web server before this code runs, and then wins; the settings screen says so.
 */
final class SearchEngineController
{
    private const MAX_AGE = 300;

    public function __construct(
        private readonly SitemapService $sitemap,
        private readonly ThemeRenderer $themes,
        private readonly Configuration $config,
        private readonly string $siteUrl,
    ) {
    }

    public function register(Router $router): void
    {
        $router->get('/robots.txt', $this->robots(...), 'seo.robots');
        $router->get('/sitemap.xml', $this->index(...), 'seo.sitemap');
        $router->get('/sitemap-{part}.xml', $this->part(...), 'seo.sitemap.part');
    }

    /**
     * @param array<string, string> $params
     */
    public function robots(array $params, Request $request): Response
    {
        $base = BasePath::get();

        if (SearchSettings::discouraged($this->config)) {
            $lines = ['User-agent: *', 'Disallow: /'];
        } else {
            $lines = [
                'User-agent: *',
                // Private areas and pages that only exist for a signed-in person or for a single download.
                'Disallow: ' . $base . '/admin/',
                'Disallow: ' . $base . '/account/',
                'Disallow: ' . $base . '/search',
                'Disallow: ' . $base . '/story/*/download/',
            ];

            if ($this->sitemap->available()) {
                $lines[] = '';
                $lines[] = 'Sitemap: ' . PublicUrl::absolute($this->siteUrl, PublicUrl::sitemap());
            }
        }

        return $this->answer($request, implode("\n", $lines) . "\n", 'text/plain; charset=utf-8');
    }

    /**
     * @param array<string, string> $params
     */
    public function index(array $params, Request $request): Response
    {
        if (!$this->sitemap->available()) {
            return $this->themes->notFound();
        }

        return $this->answer($request, $this->sitemap->index(), 'application/xml; charset=utf-8');
    }

    /**
     * @param array<string, string> $params
     */
    public function part(array $params, Request $request): Response
    {
        $body = $this->sitemap->available() ? $this->sitemap->part($params['part'] ?? '') : null;

        return $body === null ? $this->themes->notFound() : $this->answer($request, $body, 'application/xml; charset=utf-8');
    }

    private function answer(Request $request, string $body, string $type): Response
    {
        $etag = EntityTag::of($body);
        // A sitemap is for crawlers, and robots.txt would only be fetched by one, so neither is worth indexing as a page.
        $headers = ['ETag' => $etag, 'Cache-Control' => 'public, max-age=' . self::MAX_AGE, 'X-Robots-Tag' => 'noindex'];

        if (EntityTag::matches($request, $etag)) {
            return new Response('', 304, $headers);
        }

        return new Response($body, 200, ['Content-Type' => $type] + $headers);
    }
}
