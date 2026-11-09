<?php

/**
 * What the head of a public page tells search engines: noindex for a site that asked them to stay away, and a canonical address.
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
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Services\SearchSettings;

/**
 * Written by core, so every theme carries both without a template change. A controller names the canonical path of the
 * public page it is about to draw, which is how a story page and its chapters get one; nothing else prints a canonical
 * here (a static page's own is printed by the pages code). Without a configured site address a relative canonical would
 * be wrong, so none is printed.
 */
final class SearchHead
{
    private ?string $canonical = null;

    public function __construct(
        private readonly Configuration $config,
        private readonly string $siteUrl,
    ) {
    }

    public function register(HookManager $hooks): void
    {
        $hooks->addFilter('lf_noindex', $this->noindex(...), 10);
        $hooks->addAction('lf_head', $this->head(...), 12);
    }

    /**
     * @param string $path the path as PublicUrl builds it, with the install folder
     */
    public function canonical(?string $path): void
    {
        $this->canonical = $path;
    }

    public function noindex(mixed $noindex): bool
    {
        return $noindex === true || SearchSettings::discouraged($this->config);
    }

    public function head(): void
    {
        if ($this->canonical === null || $this->siteUrl === '') {
            return;
        }

        echo '<link rel="canonical" href="', Html::e(PublicUrl::absolute($this->siteUrl, $this->canonical)), '">', "\n";
    }
}
