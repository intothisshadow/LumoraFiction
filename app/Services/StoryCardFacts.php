<?php

/**
 * What a page of story cards needs besides the stories: how many chapters each has and its rating, fandoms, characters,
 * relationships and tags.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.3.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;

/**
 * Both are read for the whole page at once, so a listing makes two queries however many cards it draws. Kinds the
 * archive mode has switched off are left out, as on the story page.
 */
final class StoryCardFacts
{
    public function __construct(
        private readonly ChapterService $chapters,
        private readonly TaxonomyService $taxonomy,
    ) {
    }

    /**
     * @param list<int> $storyIds
     * @return array<int, array{chapters: int, terms: array<string, list<Term>>}> story id => facts
     */
    public function for(array $storyIds): array
    {
        $counts = $this->chapters->countsForStories($storyIds);
        $terms = $this->taxonomy->termsForStories($storyIds);
        $enabled = array_map(static fn (TermType $type): string => $type->value, $this->taxonomy->enabledTypes());
        $facts = [];

        foreach ($storyIds as $id) {
            $facts[$id] = [
                'chapters' => $counts[$id]['published'] ?? 0,
                'terms' => array_filter($terms[$id] ?? [], static fn (string $kind): bool => in_array($kind, $enabled, true), ARRAY_FILTER_USE_KEY),
            ];
        }

        return $facts;
    }
}
