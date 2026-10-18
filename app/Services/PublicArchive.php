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
use LumoraFiction\Models\Story;

/**
 * Every query here starts from StoryVisibility::publicCondition(), so a draft, a story awaiting approval and a story
 * without a published chapter can never appear in a list or a count, whatever the page asks for.
 */
final class PublicArchive
{
    public const PAGE_SIZE = 20;

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
