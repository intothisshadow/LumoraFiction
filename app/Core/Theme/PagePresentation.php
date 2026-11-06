<?php

/**
 * Puts published static pages into the main menu, the footer link list and the page head.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Theme;

use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\Page;
use LumoraFiction\Services\PageService;

/**
 * Core adds the links through the menu filters so every theme shows them without a template change. A theme asks
 * for the footer list with one template tag and never learns how pages are stored. The page being shown is told to
 * this object by the controller, which is how the menu knows which link is current and what the head should say.
 */
final class PagePresentation
{
    private ?Page $current = null;

    public function __construct(
        private readonly PageService $pages,
        private readonly string $siteUrl,
    ) {
    }

    public function register(HookManager $hooks): void
    {
        $hooks->addFilter('lf_navigation_items', $this->menu(...), 20);
        $hooks->addFilter('lf_footer_items', $this->footer(...), 10);
        $hooks->addAction('lf_head', $this->head(...));
    }

    public function showing(?Page $page): void
    {
        $this->current = $page;
    }

    public function menu(mixed $items): mixed
    {
        return is_array($items) ? array_merge($items, $this->links($this->pages->menuPages())) : $items;
    }

    public function footer(mixed $items): mixed
    {
        return is_array($items) ? array_merge($items, $this->links($this->pages->footerPages())) : $items;
    }

    /**
     * The description and the canonical address of the page being shown, and nothing on any other view.
     */
    public function head(string $view = ''): void
    {
        if ($view !== 'page' || $this->current === null) {
            return;
        }

        if ($this->current->description !== '') {
            echo '<meta name="description" content="', Html::e($this->current->description), '">', "\n";
        }

        // Without a configured site address a relative canonical would be wrong, so none is printed.
        if ($this->siteUrl !== '') {
            echo '<link rel="canonical" href="', Html::e($this->siteUrl . PublicUrl::page($this->current->slug, false)), '">', "\n";
        }
    }

    /**
     * @param list<Page> $pages
     * @return list<array{label: string, url: string, current: bool}>
     */
    private function links(array $pages): array
    {
        return array_map(fn (Page $page): array => [
            'label' => $page->title,
            'url' => PublicUrl::page($page->slug),
            'current' => $this->current !== null && $this->current->id === $page->id,
        ], $pages);
    }
}
