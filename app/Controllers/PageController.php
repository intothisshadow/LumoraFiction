<?php

/**
 * Serves published static pages at their own address.
 *
 * @package LumoraFiction
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers;

use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Theme\PagePresentation;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\PagePresenter;
use LumoraFiction\Services\PageService;

/**
 * Pages are not covered by the sign-in-to-read rule: the terms and the privacy policy must be readable by the people
 * who are asked to agree to them before they have an account. A draft and an unknown address are answered the same
 * way, so the response never says whether a page exists.
 */
final class PageController
{
    public function __construct(
        private readonly ThemeRenderer $themes,
        private readonly PageService $pages,
        private readonly PagePresenter $presenter,
        private readonly PagePresentation $presentation,
        private readonly AuthService $auth,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function show(array $params, Request $request): Response
    {
        $page = $this->pages->findPublicBySlug($params['slug'] ?? '');

        if ($page === null) {
            return $this->themes->notFound();
        }

        $this->presentation->showing($page);

        $response = $this->themes->render('page', ['page' => $this->presenter->single($page)], 200, ['title' => $page->title, 'noindex' => $page->noindex]);

        // A page drawn for a signed-in person carries their menu, so no shared cache may keep it for someone else.
        return $this->auth->check() ? $response->withHeader('Cache-Control', 'private, no-store') : $response;
    }
}
