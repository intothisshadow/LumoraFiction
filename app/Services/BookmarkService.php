<?php

/**
 * A signed-in reader's bookmarks on stories and chapters.
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
use LumoraFiction\Models\BookmarkOutcome;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\User;

/**
 * Every query is scoped to one account's id, so one reader can never see or change another's list. A bookmark is
 * checked against the public-visibility rule both when it is made and when the list is read, which is how a story
 * that is later unpublished drops out of the list without any message saying why.
 */
final class BookmarkService
{
    /** The most bookmarks one account may keep, so a script cannot fill the table. */
    public const LIMIT = 500;

    public function __construct(
        private readonly Database $database,
        private readonly StoryService $stories,
    ) {
    }

    /**
     * @param int|null $chapterNumber the chapter's number within the story, or null to bookmark the whole story
     */
    public function add(User $user, int $storyId, ?int $chapterNumber = null): BookmarkOutcome
    {
        if (!$user->can(Capability::Bookmark)) {
            return BookmarkOutcome::NotAllowed;
        }

        $chapterId = 0;

        if ($chapterNumber === null) {
            $visible = $this->database->fetchColumn(
                'SELECT s.id FROM {prefix}stories s WHERE s.id = :story AND ' . StoryVisibility::publicCondition('s'),
                ['story' => $storyId],
            );
        } else {
            $visible = $this->database->fetchColumn(
                'SELECT c.id FROM {prefix}chapters c JOIN {prefix}stories s ON s.id = c.story_id
                 WHERE s.id = :story AND c.number = :number AND ' . StoryVisibility::publicChapterCondition('c', 's'),
                ['story' => $storyId, 'number' => $chapterNumber],
            );
            $chapterId = $visible === false || $visible === null ? 0 : (int) $visible;
        }

        if ($visible === false || $visible === null) {
            return BookmarkOutcome::NotAvailable;
        }

        $existing = $this->database->fetchColumn(
            'SELECT id FROM {prefix}bookmarks WHERE user_id = :user AND story_id = :story AND chapter_id = :chapter',
            ['user' => $user->id, 'story' => $storyId, 'chapter' => $chapterId],
        );

        if ($existing !== false && $existing !== null) {
            return BookmarkOutcome::AlreadySaved;
        }

        if ($this->count($user) >= self::LIMIT) {
            return BookmarkOutcome::ListFull;
        }

        // INSERT IGNORE: two quick clicks race past the check above, and the unique key then keeps just one row.
        $this->database->execute(
            'INSERT IGNORE INTO {prefix}bookmarks (user_id, story_id, chapter_id, created_at) VALUES (:user, :story, :chapter, :created)',
            ['user' => $user->id, 'story' => $storyId, 'chapter' => $chapterId, 'created' => date('Y-m-d H:i:s')],
        );

        return BookmarkOutcome::Added;
    }

    /**
     * Removes one of the reader's own bookmarks. Someone else's id changes nothing, and the answer is the same.
     */
    public function remove(User $user, int $bookmarkId): bool
    {
        return $this->database->execute(
            'DELETE FROM {prefix}bookmarks WHERE id = :id AND user_id = :user',
            ['id' => $bookmarkId, 'user' => $user->id],
        ) > 0;
    }

    /**
     * The reader's bookmarks on one story, as chapter id => bookmark id, where chapter id 0 is the whole story.
     *
     * @return array<int, int>
     */
    public function forStory(User $user, int $storyId): array
    {
        $found = [];

        foreach ($this->database->fetchAll(
            'SELECT id, chapter_id FROM {prefix}bookmarks WHERE user_id = :user AND story_id = :story',
            ['user' => $user->id, 'story' => $storyId],
        ) as $row) {
            $found[(int) $row['chapter_id']] = (int) $row['id'];
        }

        return $found;
    }

    /**
     * The bookmarks that can still be opened, newest first. A bookmark on a story or chapter that is no longer public
     * is left out.
     *
     * @return list<array{id: int, story: Story, chapter_number: ?int, chapter_title: ?string}>
     */
    public function listFor(User $user, int $limit, int $offset): array
    {
        $rows = $this->database->fetchAll(
            'SELECT s.*, b.id AS bookmark_id, c.number AS bookmark_chapter_number, c.title AS bookmark_chapter_title
             FROM {prefix}bookmarks b
             JOIN {prefix}stories s ON s.id = b.story_id
             LEFT JOIN {prefix}chapters c ON c.id = b.chapter_id AND c.story_id = b.story_id
             WHERE b.user_id = :user AND ' . StoryVisibility::publicCondition('s')
            . " AND (b.chapter_id = 0 OR c.publication_state = 'published')
             ORDER BY b.created_at DESC, b.id DESC LIMIT " . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            ['user' => $user->id],
        );

        $stories = $this->stories->fromRows($rows);
        $list = [];

        foreach ($rows as $index => $row) {
            $number = $row['bookmark_chapter_number'];

            $list[] = [
                'id' => (int) $row['bookmark_id'],
                'story' => $stories[$index],
                'chapter_number' => $number === null ? null : (int) $number,
                'chapter_title' => $number === null ? null : (string) $row['bookmark_chapter_title'],
            ];
        }

        return $list;
    }

    /**
     * How many bookmarks can still be opened, counted by the same rule as the list.
     */
    public function countVisible(User $user): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM {prefix}bookmarks b
             JOIN {prefix}stories s ON s.id = b.story_id
             LEFT JOIN {prefix}chapters c ON c.id = b.chapter_id AND c.story_id = b.story_id
             WHERE b.user_id = :user AND ' . StoryVisibility::publicCondition('s')
            . " AND (b.chapter_id = 0 OR c.publication_state = 'published')",
            ['user' => $user->id],
        );
    }

    /**
     * Every row an account owns, hidden stories included. This is what the limit counts.
     */
    public function count(User $user): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}bookmarks WHERE user_id = :user', ['user' => $user->id]);
    }

    /**
     * Deletes everything an account has bookmarked. Account deletion calls this.
     */
    public function deleteForUser(int $userId): int
    {
        return $this->database->execute('DELETE FROM {prefix}bookmarks WHERE user_id = :user', ['user' => $userId]);
    }
}
