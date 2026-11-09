<?php

/**
 * A whole feed: what it is about, where it lives and its newest entries.
 *
 * @package LumoraFiction
 * @subpackage Feed
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Feed;

use DateTimeImmutable;

final readonly class Feed
{
    /**
     * @param string $homeUrl the page the feed is about
     * @param string $rssUrl this feed's own address in the RSS form
     * @param string $jsonUrl its address as a JSON Feed, or an empty string when JSON feeds are switched off
     * @param list<FeedItem> $items newest first
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $homeUrl,
        public string $rssUrl,
        public string $jsonUrl,
        public array $items,
        public string $image = '',
    ) {
    }

    /**
     * The date of the entry that changed last, which is what readers show as the feed's own date.
     */
    public function updated(): ?DateTimeImmutable
    {
        $latest = null;

        foreach ($this->items as $item) {
            foreach ([$item->date, $item->modified] as $moment) {
                if ($moment !== null && ($latest === null || $moment > $latest)) {
                    $latest = $moment;
                }
            }
        }

        return $latest;
    }
}
