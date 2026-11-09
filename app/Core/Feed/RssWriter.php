<?php

/**
 * Writes a feed as RSS 2.0.
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

/**
 * HTML inside an entry is written as escaped text, never as CDATA: a CDATA section cannot hold the sequence that ends
 * it, and a story's text can contain anything, so escaping is the only form that is always well formed.
 */
final class RssWriter
{
    public static function write(Feed $feed): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:media="http://search.yahoo.com/mrss/">' . "\n";
        $xml .= "<channel>\n";
        $xml .= self::element('title', $feed->title);
        $xml .= self::element('link', $feed->homeUrl);
        // The description is required by the format, so an empty one falls back to the title.
        $xml .= self::element('description', $feed->description !== '' ? $feed->description : $feed->title);

        $updated = $feed->updated();

        if ($updated !== null) {
            $xml .= self::element('lastBuildDate', self::date($updated));
        }

        $xml .= '<atom:link href="' . self::escape($feed->rssUrl) . '" rel="self" type="application/rss+xml"/>' . "\n";

        if ($feed->image !== '') {
            $xml .= '<image>' . self::element('url', $feed->image) . self::element('title', $feed->title) . self::element('link', $feed->homeUrl) . "</image>\n";
        }

        foreach ($feed->items as $item) {
            $xml .= self::item($item);
        }

        return $xml . "</channel>\n</rss>\n";
    }

    private static function item(FeedItem $item): string
    {
        $xml = "<item>\n";
        $xml .= self::element('title', $item->title);
        $xml .= self::element('link', $item->url);
        $xml .= '<guid isPermaLink="' . ($item->guidIsAddress ? 'true' : 'false') . '">' . self::escape($item->id) . "</guid>\n";

        if ($item->date !== null) {
            $xml .= self::element('pubDate', self::date($item->date));
        }

        if ($item->author !== '') {
            $xml .= self::element('dc:creator', $item->author);
        }

        foreach ($item->categories as $category) {
            $xml .= self::element('category', $category);
        }

        $xml .= self::element('description', $item->summaryHtml);

        if ($item->contentHtml !== '') {
            $xml .= self::element('content:encoded', $item->contentHtml);
        }

        if ($item->image !== '') {
            $xml .= '<media:thumbnail url="' . self::escape($item->image) . '"/>' . "\n";
        }

        return $xml . "</item>\n";
    }

    private static function element(string $name, string $value): string
    {
        return '<' . $name . '>' . self::escape($value) . '</' . $name . ">\n";
    }

    private static function date(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format(DATE_RSS);
    }

    /**
     * Control characters XML 1.0 forbids are dropped first; one pasted into a chapter would make the whole feed fail to
     * parse for every reader.
     */
    public static function escape(string $text): string
    {
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
