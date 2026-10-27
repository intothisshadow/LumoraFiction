<?php

/**
 * Turns the download decision into the plain data a theme draws as the story page's download menu.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Models\Story;

/**
 * One bundle, passed on whole by the theme, so a field added later reaches every theme without editing them. It is an
 * empty list whenever nothing can be downloaded, which is what hides the menu.
 */
final class DownloadPresenter
{
    public function __construct(
        private readonly Configuration $config,
        private readonly DownloadAccess $access,
        private readonly StoryDownloads $downloads,
        private readonly DownloadCounter $counter,
    ) {
    }

    /**
     * @return array{label: string, formats: list<array{key: string, label: string, description: string, url: string, count: ?int}>, show_counts: bool}|array{}
     */
    public function menu(Story $story): array
    {
        $formats = $this->downloads->offered();

        // The same decision the download address makes, so the menu never offers what the address would refuse.
        if ($formats === [] || !$this->access->allows($story)) {
            return [];
        }

        $showCounts = DownloadSettings::showingCounts($this->config);
        $totals = $showCounts ? $this->counter->totals($story->id) : [];
        $items = [];

        foreach ($formats as $format) {
            $items[] = [
                'key' => $format->value,
                'label' => $format->label(),
                'description' => $format->description(),
                'url' => PublicUrl::download($story->slug, $format->value),
                'count' => $showCounts ? ($totals[$format->value] ?? 0) : null,
            ];
        }

        return ['label' => 'Download', 'formats' => $items, 'show_counts' => $showCounts];
    }
}
