<?php

/**
 * Writes a feed as a JSON Feed.
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
use DateTimeZone;

final class JsonFeedWriter
{
    public const VERSION = 'https://jsonfeed.org/version/1.1';

    public static function write(Feed $feed): string
    {
        $data = [
            'version' => self::VERSION,
            'title' => $feed->title,
            'home_page_url' => $feed->homeUrl,
            'feed_url' => $feed->jsonUrl,
            'description' => $feed->description,
            'items' => array_map(self::item(...), $feed->items),
        ];

        if ($feed->image !== '') {
            $data['icon'] = $feed->image;
        }

        // An invalid byte in a story's text must not make the whole feed fail, so it is substituted rather than thrown on.
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(FeedItem $item): array
    {
        $entry = [
            'id' => $item->id,
            'url' => $item->url,
            'title' => $item->title,
            // The format needs some content; the summary stands in when the feed carries no full text.
            'content_html' => $item->contentHtml !== '' ? $item->contentHtml : $item->summaryHtml,
        ];

        $summary = trim(html_entity_decode(strip_tags($item->summaryHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($summary !== '') {
            $entry['summary'] = $summary;
        }

        if ($item->image !== '') {
            $entry['image'] = $item->image;
        }

        if ($item->date !== null) {
            $entry['date_published'] = self::date($item->date);
        }

        if ($item->modified !== null) {
            $entry['date_modified'] = self::date($item->modified);
        }

        if ($item->author !== '') {
            $entry['authors'] = [array_filter(['name' => $item->author, 'url' => $item->authorUrl], static fn (string $value): bool => $value !== '')];
        }

        if ($item->categories !== []) {
            $entry['tags'] = $item->categories;
        }

        return $entry;
    }

    private static function date(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
