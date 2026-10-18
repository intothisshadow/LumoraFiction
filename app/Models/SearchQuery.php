<?php

/**
 * A validated public story search: words, filters and a sort, with nothing a visitor typed left unchecked.
 *
 * @package LumoraFiction
 * @subpackage Models
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Models;

final readonly class SearchQuery
{
    /**
     * @param list<string> $words the words to find, each already length-checked
     * @param array<string, int> $termIds kind name => term id; 0 when the name matched no term, which matches no story
     * @param array<string, string> $termNames kind name => the name as it was typed, so a filter can be shown and removed
     */
    public function __construct(
        public string $text = '',
        public array $words = [],
        public array $termIds = [],
        public array $termNames = [],
        public ?WorkStatus $status = null,
        public string $language = '',
        public ?int $minWords = null,
        public ?int $maxWords = null,
        public string $sort = 'updated',
    ) {
    }

    /**
     * Whether anything narrows the list. A search with no words and no filters is simply the whole archive.
     */
    public function isNarrowed(): bool
    {
        return $this->words !== [] || $this->termIds !== [] || $this->status !== null || $this->language !== ''
            || $this->minWords !== null || $this->maxWords !== null;
    }
}
