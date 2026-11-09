<?php

/**
 * Tells browsers and feed readers where the site's feeds are, from the head of every public page.
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

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Http\BasePath;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Services\FeedSettings;
use LumoraFiction\Services\NewsSettings;

/**
 * Written by core, so every theme advertises the feeds without a template change, and only the kinds that are switched
 * on. The links are the site-wide ones; a reader finds the others from their addresses.
 */
final class FeedLinks
{
    public function __construct(
        private readonly Configuration $config,
        private readonly string $siteUrl,
    ) {
    }

    public function register(HookManager $hooks): void
    {
        $hooks->addAction('lf_head', $this->head(...), 15);
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

        if (FeedSettings::offered($this->config, 'news') && NewsSettings::enabled($this->config)) {
            $this->link('application/rss+xml', $name . ': news', PublicUrl::feed(['news']));
        }
    }

    private function link(string $type, string $title, string $path): void
    {
        $base = BasePath::get();
        $address = $this->siteUrl === '' ? $path : rtrim($this->siteUrl, '/') . ($base !== '' && str_starts_with($path, $base) ? substr($path, strlen($base)) : $path);

        echo '<link rel="alternate" type="', Html::e($type), '" title="', Html::e($title), '" href="', Html::e($address), '">', "\n";
    }
}
