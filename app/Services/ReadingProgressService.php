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

    public function record(User $user, int $storyId, int $chapterId): void
    {
        $now = date('Y-m-d H:i:s');

        $this->database->execute(
            'INSERT INTO {prefix}reading_progress (user_id, story_id, chapter_id, updated_at) VALUES (:user, :story, :chapter, :updated)
             ON DUPLICATE KEY UPDATE chapter_id = :chapter_again, updated_at = :updated_again',
            ['user' => $user->id, 'story' => $storyId, 'chapter' => $chapterId, 'updated' => $now, 'chapter_again' => $chapterId, 'updated_again' => $now],
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
     * The stories the reader read most recently that can still be opened, with the chapter they were on.
     *
     * @return list<array{story: Story, number: int, title: string}>
     */
    public function recent(User $user, int $limit): array
    {
        $rows = $this->database->fetchAll(
            'SELECT s.*, c.number AS progress_number, c.title AS progress_title
             FROM {prefix}reading_progress p
             JOIN {prefix}stories s ON s.id = p.story_id
             JOIN {prefix}chapters c ON c.id = p.chapter_id AND c.story_id = p.story_id
             WHERE p.user_id = :user AND ' . StoryVisibility::publicCondition('s') . " AND c.publication_state = 'published'
             ORDER BY p.updated_at DESC, p.story_id DESC LIMIT " . max(1, min(20, $limit)),
            ['user' => $user->id],
        );

        $stories = $this->stories->fromRows($rows);
        $list = [];

        foreach ($rows as $index => $row) {
            $list[] = ['story' => $stories[$index], 'number' => (int) $row['progress_number'], 'title' => (string) $row['progress_title']];
        }

        return $list;
    }

    /**
     * Deletes everything an account has read. Account deletion calls this.
     */
    public function deleteForUser(int $userId): int
    {
        return $this->database->execute('DELETE FROM {prefix}reading_progress WHERE user_id = :user', ['user' => $userId]);
    }
}
