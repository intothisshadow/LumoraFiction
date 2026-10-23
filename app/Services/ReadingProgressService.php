<?php

/**
 * Remembers the chapter a signed-in reader opened last in each story.
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
use LumoraFiction\Models\User;

/**
 * Only ever called with a signed-in account, so a guest is never recorded and nothing identifies one. Reading is
 * scoped to the account's id, and a story or chapter that has stopped being public is not offered back.
 */
final class ReadingProgressService
{
    public function __construct(
        private readonly Database $database,
        private readonly StoryService $stories,
    ) {
    }

    /** The largest saved place: the share of a chapter read, in thousandths. */
    public const POSITION_MAX = 1000;

    public function record(User $user, int $storyId, int $chapterId): void
    {
        $now = date('Y-m-d H:i:s');

        // MySQL applies the assignments left to right, so position is decided against the chapter the row still holds
        // before chapter_id is overwritten: a different chapter starts again at the top.
        $this->database->execute(
            'INSERT INTO {prefix}reading_progress (user_id, story_id, chapter_id, updated_at) VALUES (:user, :story, :chapter, :updated)
             ON DUPLICATE KEY UPDATE position = IF(chapter_id = :chapter_same, position, 0), chapter_id = :chapter_again, updated_at = :updated_again',
            ['user' => $user->id, 'story' => $storyId, 'chapter' => $chapterId, 'updated' => $now, 'chapter_same' => $chapterId, 'chapter_again' => $chapterId, 'updated_again' => $now],
        );
    }

    /**
     * Stores how far down a chapter the reader got. The place is kept only when it is a whole number from 0 to 1000,
     * the chapter can be opened by anyone, and it is the chapter the reader's row points at; anything else changes
     * nothing. The visit time is left alone so saving a place never moves the "since you last read" mark.
     *
     * @return bool true when a place was stored
     */
    public function savePosition(User $user, int $storyId, int $chapterId, int $position): bool
    {
        if ($position < 0 || $position > self::POSITION_MAX) {
            return false;
        }

        return $this->database->execute(
            'UPDATE {prefix}reading_progress p
             JOIN {prefix}chapters c ON c.id = p.chapter_id AND c.story_id = p.story_id
             JOIN {prefix}stories s ON s.id = p.story_id
             SET p.position = :position
             WHERE p.user_id = :user AND p.story_id = :story AND p.chapter_id = :chapter AND ' . StoryVisibility::publicChapterCondition('c', 's'),
            ['position' => $position, 'user' => $user->id, 'story' => $storyId, 'chapter' => $chapterId],
        ) > 0;
    }

    /**
     * The saved place in one chapter, 0 when the reader's row points at another chapter or none is saved.
     */
    public function positionIn(User $user, int $storyId, int $chapterId): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT position FROM {prefix}reading_progress WHERE user_id = :user AND story_id = :story AND chapter_id = :chapter',
            ['user' => $user->id, 'story' => $storyId, 'chapter' => $chapterId],
        );
    }

    /**
     * Where the reader left off in one story, or null when they have not started it or that chapter is gone.
     *
     * @return array{number: int, title: string}|null
     */
    public function forStory(User $user, int $storyId): ?array
    {
        $row = $this->database->fetchOne(
            'SELECT c.number, c.title FROM {prefix}reading_progress p
             JOIN {prefix}chapters c ON c.id = p.chapter_id AND c.story_id = p.story_id
             JOIN {prefix}stories s ON s.id = p.story_id
             WHERE p.user_id = :user AND p.story_id = :story AND ' . StoryVisibility::publicChapterCondition('c', 's'),
            ['user' => $user->id, 'story' => $storyId],
        );

        return $row === null ? null : ['number' => (int) $row['number'], 'title' => (string) $row['title']];
    }

    /**
     * The stories the reader has read that can still be opened, most recently read first. Each entry carries the
     * chapter to resume at, how far through it the reader got, when it was read, and whether that chapter is the last
     * one published. A story or chapter that is no longer public is left out.
     *
     * @return list<array{story: Story, number: int, title: string, position: int, read_at: string, finished: bool}>
     */
    public function listFor(User $user, int $limit, int $offset): array
    {
        $rows = $this->database->fetchAll(
            'SELECT s.*, c.number AS progress_number, c.title AS progress_title, p.position AS progress_position, p.updated_at AS progress_read_at,
                    c.number >= (SELECT MAX(lc.number) FROM {prefix}chapters lc WHERE lc.story_id = s.id AND lc.publication_state = \'published\') AS progress_finished
             FROM {prefix}reading_progress p
             JOIN {prefix}stories s ON s.id = p.story_id
             JOIN {prefix}chapters c ON c.id = p.chapter_id AND c.story_id = p.story_id
             WHERE p.user_id = :user AND ' . StoryVisibility::publicCondition('s') . " AND c.publication_state = 'published'
             ORDER BY p.updated_at DESC, p.story_id DESC LIMIT " . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            ['user' => $user->id],
        );

        $stories = $this->stories->fromRows($rows);
        $list = [];

        foreach ($rows as $index => $row) {
            $list[] = [
                'story' => $stories[$index],
                'number' => (int) $row['progress_number'],
                'title' => (string) $row['progress_title'],
                'position' => (int) $row['progress_position'],
                'read_at' => (string) $row['progress_read_at'],
                'finished' => (int) $row['progress_finished'] === 1,
            ];
        }

        return $list;
    }

    /**
     * How many entries listFor() can show, counted by the same rule.
     */
    public function countFor(User $user): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM {prefix}reading_progress p
             JOIN {prefix}stories s ON s.id = p.story_id
             JOIN {prefix}chapters c ON c.id = p.chapter_id AND c.story_id = p.story_id
             WHERE p.user_id = :user AND ' . StoryVisibility::publicCondition('s') . " AND c.publication_state = 'published'",
            ['user' => $user->id],
        );
    }

    /**
     * Forgets one story for the reader. Another account's story id removes nothing.
     */
    public function forget(User $user, int $storyId): bool
    {
        return $this->database->execute(
            'DELETE FROM {prefix}reading_progress WHERE user_id = :user AND story_id = :story',
            ['user' => $user->id, 'story' => $storyId],
        ) > 0;
    }

    /**
     * Deletes everything an account has read. Account deletion calls this.
     */
    public function deleteForUser(int $userId): int
    {
        return $this->database->execute('DELETE FROM {prefix}reading_progress WHERE user_id = :user', ['user' => $userId]);
    }
}
