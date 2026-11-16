<?php

/**
 * One entry of a feed, in the neutral shape both the RSS and the JSON writer read.
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

/**
 * Every address is absolute and every text is already safe HTML, so a writer only has to escape for its own format.
 * An item carries a public display name and address only, never anything that identifies an account's login.
 */
final readonly class FeedItem
{
    /**
     * @param string $id stable identifier; the address itself when guidIsAddress is true
     * @param DateTimeImmutable|null $date the date a reader sorts by
     * @param DateTimeImmutable|null $modified when the entry last changed, if later than $date
     * @param string $summaryHtml a short description
     * @param string $contentHtml the full text, or an empty string when the feed carries only the summary
     * @param list<string> $categories
     */
    public function __construct(
        public string $id,
        public string $url,
        public string $title,
        public ?DateTimeImmutable $date,
        public ?DateTimeImmutable $modified = null,
        public string $author = '',
        public string $authorUrl = '',
        public string $summaryHtml = '',
        public string $contentHtml = '',
        public string $image = '',
        public array $categories = [],
        public bool $guidIsAddress = false,
        public string $enclosureType = '',
        public int $enclosureBytes = 0,
    ) {
    }
}
