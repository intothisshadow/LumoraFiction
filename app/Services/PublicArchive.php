<?php

/**
 * The lists of public stories shown on the home page, author pages and term pages.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Database\Database;
use LumoraFiction\Models\SearchQuery;
use LumoraFiction\Models\Story;

/**
 * Every query here starts from StoryVisibility::publicCondition(), so a draft, a story awaiting approval and a story
 * without a published chapter can never appear in a list or a count, whatever the page asks for.
 */
final class PublicArchive
{
    public const PAGE_SIZE = 20;

    /** The most matches a search counts or pages through; a narrower search is the way to see more. */
    public const SEARCH_LIMIT = 1000;

    public const DEFAULT_SORT = 'updated';

    /** Sort name => label. The order here is the order a theme shows the choices in. */
    public const SORTS = [
        'updated' => 'Recently updated',
        'published' => 'Recently published',
        'title' => 'Title',
        'words' => 'Word count',
    ];

    /** Sort name => ORDER BY. A fixed list, so request text never reaches the query. */
    private const ORDER = [
        'updated' => 's.updated_at DESC, s.id DESC',
        'published' => 's.published_at DESC, s.id DESC',
        'title' => 's.title ASC, s.id ASC',
        'words' => 's.word_count DESC, s.id DESC',
    ];

    public function __construct(
        private readonly Database $database,
        private readonly StoryService $stories,
    ) {
    }

    /**
     * Turns a sort name from an address into one this class knows, falling back to the default.
     */
    public static function sortOrDefault(?string $sort): string
    {
        return $sort !== null && isset(self::SORTS[$sort]) ? $sort : self::DEFAULT_SORT;
    }

    /**
     * @return list<Story>
     */
    public function recent(string $sort, int $limit): array
    {
        return $this->stories->fromRows($this->database->fetchAll(
            'SELECT s.* FROM {prefix}stories s WHERE ' . StoryVisibility::publicCondition('s')
            . ' ORDER BY ' . self::ORDER[self::sortOrDefault($sort)] . ' LIMIT ' . max(1, min(100, $limit)),
        ));
    }

    /**
     * @return list<Story>
     */
    public function forAuthor(int $ownerId, string $sort, int $limit, int $offset): array
    {
        return $this->stories->fromRows($this->database->fetchAll(
            'SELECT s.* FROM {prefix}stories s WHERE s.owner_id = :owner AND ' . StoryVisibility::publicCondition('s')
            . ' ORDER BY ' . self::ORDER[self::sortOrDefault($sort)] . ' LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            ['owner' => $ownerId],
        ));
    }

    public function countForAuthor(int $ownerId): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM {prefix}stories s WHERE s.owner_id = :owner AND ' . StoryVisibility::publicCondition('s'),
            ['owner' => $ownerId],
        );
    }

    /**
     * @return list<Story>
     */
    public function forTerm(int $termId, string $sort, int $limit, int $offset): array
    {
        return $this->stories->fromRows($this->database->fetchAll(
            'SELECT s.* FROM {prefix}stories s JOIN {prefix}story_terms st ON st.story_id = s.id
             WHERE st.term_id = :term AND ' . StoryVisibility::publicCondition('s')
            . ' ORDER BY ' . self::ORDER[self::sortOrDefault($sort)] . ' LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            ['term' => $termId],
        ));
    }

    /**
     * @return list<Story>
     */
    public function search(SearchQuery $query, int $limit, int $offset): array
    {
        [$where, $params] = $this->searchCondition($query);

        return $this->stories->fromRows($this->database->fetchAll(
            'SELECT s.* FROM {prefix}stories s WHERE ' . $where
            . ' ORDER BY ' . self::ORDER[self::sortOrDefault($query->sort)] . ' LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, min(self::SEARCH_LIMIT, $offset)),
            $params,
        ));
    }

    /**
     * The number of matches, stopping at SEARCH_LIMIT. Counting further would cost a full scan on a large archive
     * for a number nobody reads, and pages past the cap are never offered.
     */
    public function countSearch(SearchQuery $query): int
    {
        [$where, $params] = $this->searchCondition($query);

        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM (SELECT 1 FROM {prefix}stories s WHERE ' . $where . ' LIMIT ' . (self::SEARCH_LIMIT + 1) . ') matches',
            $params,
        );
    }

    /**
     * The languages public stories are written in, for the language filter.
     *
     * @return list<string>
     */
    public function languages(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT DISTINCT s.language FROM {prefix}stories s WHERE ' . StoryVisibility::publicCondition('s') . ' ORDER BY s.language LIMIT 100',
        );

        return array_values(array_map(static fn (array $row): string => (string) $row['language'], $rows));
    }

    /**
     * Every clause is a bound parameter; the visibility rule comes first and cannot be loosened by any filter.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function searchCondition(SearchQuery $query): array
    {
        $where = [StoryVisibility::publicCondition('s')];
        $params = [];

        foreach ($query->words as $index => $word) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word) . '%';
            $where[] = "(s.title LIKE :w{$index}_title OR s.summary LIKE :w{$index}_summary"
                . " OR EXISTS (SELECT 1 FROM {prefix}users wu WHERE wu.id = s.owner_id AND wu.display_name LIKE :w{$index}_author)"
                . " OR EXISTS (SELECT 1 FROM {prefix}story_terms wst JOIN {prefix}terms wt ON wt.id = wst.term_id"
                . " WHERE wst.story_id = s.id AND wt.type = 'tag' AND wt.name LIKE :w{$index}_tag))";

            foreach (['title', 'summary', 'author', 'tag'] as $field) {
                $params["w{$index}_{$field}"] = $like;
            }
        }

        $position = 0;

        foreach ($query->termIds as $termId) {
            $where[] = "EXISTS (SELECT 1 FROM {prefix}story_terms ft{$position} WHERE ft{$position}.story_id = s.id AND ft{$position}.term_id = :term{$position})";
            $params["term{$position}"] = $termId;
            $position++;
        }

        if ($query->status !== null) {
            $where[] = 's.work_status = :work_status';
            $params['work_status'] = $query->status->value;
        }

        if ($query->language !== '') {
            $where[] = 's.language = :language';
            $params['language'] = $query->language;
        }

        if ($query->minWords !== null) {
            $where[] = 's.word_count >= :min_words';
            $params['min_words'] = $query->minWords;
        }

        if ($query->maxWords !== null) {
            $where[] = 's.word_count <= :max_words';
            $params['max_words'] = $query->maxWords;
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Counted from the stories themselves rather than the stored story count, because a page must never claim
     * stories it cannot show.
     */
    public function countForTerm(int $termId): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM {prefix}stories s JOIN {prefix}story_terms st ON st.story_id = s.id
             WHERE st.term_id = :term AND ' . StoryVisibility::publicCondition('s'),
            ['term' => $termId],
        );
    }
}
