<?php

/**
 * Where a public page points at feeds: in its head, in a visible link, and in the footer.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Theme;

use Closure;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Services\FeedSettings;
use LumoraFiction\Services\NewsSettings;

/**
 * Written by core, so every theme advertises the feeds without a template change, and only the kinds that are switched
 * on and really served. The site-wide and news feeds are advertised on every page. A controller also says which feed
 * the page it is about to draw belongs to (an author's, a fandom's, a story's), and that one is advertised too; a page
 * that is missing, private or a preview never says anything, because its controller never gets that far.
 */
final class FeedLinks
{
    /** @var array{kind: string, label: string, segments: list<string>}|null */
    private ?array $showing = null;

    /**
     * @param (Closure(): bool)|null $commentsOn whether comments are switched on, which a story's comment feed also needs
     */
    public function __construct(
        private readonly Configuration $config,
        private readonly string $siteUrl,
        private readonly ?Closure $commentsOn = null,
    ) {
    }

    public function register(HookManager $hooks): void
    {
        $hooks->addAction('lf_head', $this->head(...), 15);
        $hooks->addFilter('lf_page_feed', $this->pageFeed(...), 10);
        $hooks->addFilter('lf_footer_items', $this->footer(...), 20);
    }

    /**
     * @param string $kind author, fandom, genre, tag, story or news: the kind of feed the page belongs to
     * @param string $label what the feed is of, such as a story's title, for the link's accessible name
     * @param list<string> $segments the feed's address after /feed, such as ['author', 'ann']
     */
    public function showing(string $kind, string $label, array $segments): void
    {
        $this->showing = ['kind' => $kind, 'label' => $label, 'segments' => $segments];
    }

    public function head(): void
    {
        $name = (string) $this->config->setting('site.name', 'Lumora Fiction');
        $json = FeedSettings::jsonEnabled($this->config);

        if (FeedSettings::offered($this->config, 'site')) {
            $this->link('application/rss+xml', $name . ': new and updated stories', PublicUrl::feed());

            if ($json) {
                $this->link('application/feed+json', $name . ': new and updated stories (JSON Feed)', PublicUrl::feed([], true));
            }
        }

        if ($this->newsOffered()) {
            $this->link('application/rss+xml', $name . ': news', PublicUrl::feed(['news']));
        }

        $own = $this->own();

        // The news feed is already advertised above, so a news page has nothing of its own to add.
        if ($own !== null && $own['kind'] !== 'news') {
            $this->link('application/rss+xml', $name . ': ' . $own['label'], PublicUrl::feed($own['segments']));

            if ($json) {
                $this->link('application/feed+json', $name . ': ' . $own['label'] . ' (JSON Feed)', PublicUrl::feed($own['segments'], true));
            }

            if ($own['kind'] === 'story' && $this->commentsOffered()) {
                $this->link('application/rss+xml', $name . ': comments on ' . $own['label'], PublicUrl::feed([...$own['segments'], 'comments']));
            }
        }
    }

    /**
     * The link a theme prints with lf_feed_link(): the page's own feed, or nothing.
     */
    public function pageFeed(mixed $link): mixed
    {
        $own = $this->own();

        return $own === null ? $link : ['label' => $own['label'], 'url' => PublicUrl::feed($own['segments'])];
    }

    /**
     * The links in the footer of every page: the site's feed and, while it is on, the news feed.
     */
    public function footer(mixed $items): mixed
    {
        if (!is_array($items)) {
            return $items;
        }

        if (FeedSettings::offered($this->config, 'site')) {
            $items[] = ['label' => 'Feed', 'url' => PublicUrl::feed(), 'current' => false, 'icon' => 'feed'];
        }

        if ($this->newsOffered()) {
            $items[] = ['label' => 'News feed', 'url' => PublicUrl::feed(['news']), 'current' => false, 'icon' => 'feed'];
        }

        return $items;
    }

    /**
     * The feed of the page being shown, when it is one the site really serves.
     *
     * @return array{kind: string, label: string, segments: list<string>}|null
     */
    private function own(): ?array
    {
        if ($this->showing === null) {
            return null;
        }

        $kind = $this->showing['kind'];

        if ($kind === 'news' ? !$this->newsOffered() : !FeedSettings::offered($this->config, $kind)) {
            return null;
        }

        return $this->showing;
    }

    private function newsOffered(): bool
    {
        return FeedSettings::offered($this->config, 'news') && NewsSettings::enabled($this->config);
    }

    private function commentsOffered(): bool
    {
        return FeedSettings::offered($this->config, 'comments') && ($this->commentsOn === null || ($this->commentsOn)());
    }

    private function link(string $type, string $title, string $path): void
    {
        echo '<link rel="alternate" type="', Html::e($type), '" title="', Html::e($title), '" href="', Html::e($this->siteUrl === '' ? $path : PublicUrl::absolute($this->siteUrl, $path)), '">', "\n";
    }
}
