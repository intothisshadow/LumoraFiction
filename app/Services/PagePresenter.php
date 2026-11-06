<?php

/**
 * Decides which fields of a static page a theme may see.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Theme\SafeHtml;
use LumoraFiction\Models\Page;

/**
 * The public page and the administrator's preview hand a theme the same shape. The text is rendered through the
 * same sanitising pipeline as a chapter, so a page cannot carry a script or unsafe markup whichever editor wrote it.
 */
final class PagePresenter
{
    public function __construct(private readonly ContentRenderer $renderer)
    {
    }

    /**
     * @return array{title: string, slug: string, html: SafeHtml, updated: string}
     */
    public function single(Page $page): array
    {
        return [
            'title' => $page->title,
            'slug' => $page->slug,
            'html' => new SafeHtml($this->renderer->render($page->content, $page->format)),
            'updated' => $page->updatedAt->format('j F Y'),
        ];
    }
}
