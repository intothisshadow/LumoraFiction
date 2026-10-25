<?php

/**
 * The public news pages: the list of announcements and a single post.
 *
 * @package LumoraFiction
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Models\NewsPost;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\NewsPresenter;
use LumoraFiction\Services\NewsService;
use LumoraFiction\Services\NewsSettings;

/**
 * News is not covered by the sign-in-to-read rule: it is how a site tells everyone about downtime or a change in the rules,
 * including people who cannot get in. A draft, and any post while news is switched off, is answered like a missing page.
 */
final class NewsController
{
    /** Pages beyond this are refused outright, so a huge number in an address cannot become a huge database offset. */
    private const MAX_PAGE = 10000;

    public function __construct(
        private readonly ThemeRenderer $themes,
        private readonly NewsService $news,
        private readonly NewsPresenter $presenter,
        private readonly AuthService $auth,
        private readonly Configuration $config,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function archive(array $params, Request $request): Response
    {
        if (!NewsSettings::enabled($this->config)) {
            return $this->themes->notFound();
        }

        $total = $this->news->countPublic();
        $pages = max(1, (int) ceil($total / NewsService::PAGE_SIZE));
        $requested = $request->queryValue('page');
        $page = $requested === null ? 1 : (preg_match('/^[1-9][0-9]{0,8}\z/', $requested) === 1 ? (int) $requested : 0);

        if ($page < 1 || $page > $pages || $page > self::MAX_PAGE) {
            return $this->themes->notFound();
        }

        $posts = $total === 0 ? [] : $this->news->listPublic(NewsService::PAGE_SIZE, ($page - 1) * NewsService::PAGE_SIZE);

        return $this->shielded($this->themes->render('news-archive', [
            'title' => 'News',
            'posts' => $this->presenter->cards($posts, static fn (NewsPost $post): string => PublicUrl::newsPost($post->slug)),
            'pagination' => ['page' => $page, 'pages' => $pages, 'url' => PublicUrl::news() . '?page={page}'],
        ], 200, ['title' => 'News']));
    }

    /**
     * @param array<string, string> $params
     */
    public function post(array $params, Request $request): Response
    {
        $post = NewsSettings::enabled($this->config) ? $this->news->findPublicBySlug($params['slug'] ?? '') : null;

        if ($post === null) {
            return $this->themes->notFound();
        }

        return $this->shielded($this->themes->render('news-single', [
            'post' => $this->presenter->single($post, PublicUrl::newsPost($post->slug)),
        ], 200, ['title' => $post->title]));
    }

    /**
     * A page drawn for a signed-in person carries their menu, so no shared cache may keep it for someone else.
     */
    private function shielded(Response $response): Response
    {
        return $this->auth->check() ? $response->withHeader('Cache-Control', 'private, no-store') : $response;
    }
}
