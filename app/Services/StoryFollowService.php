<?php

/**
 * The stories a signed-in reader follows, and what is new in them since the reader last looked.
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
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\FollowOutcome;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\User;

/**
 * Following is always an explicit choice: nothing here is called by reading or bookmarking. Every query is scoped to
 * one account's id, and every story passes the public-visibility rule, so a draft or hidden chapter never raises a
 * notice. "Since you last read" is measured from the reader's last visit to the story, or from when they began
 * following if they have not read it. A chapter counts as opened when it is numbered no higher than the one the
 * reader was last on, because progress keeps one row per story.
 */
final class StoryFollowService
{
    /** The most stories one account may follow, so a script cannot fill the table. */
    public const LIMIT = 500;

    public function __construct(
        private readonly Database $database,
        private readonly StoryService $stories,
    ) {
    }

    public function follow(User $user, int $storyId): FollowOutcome
    {
        if (!$user->can(Capability::Bookmark)) {
            return FollowOutcome::NotAllowed;
        }

        $visible = $this->database->fetchColumn(
            'SELECT s.id FROM {prefix}stories s WHERE s.id = :story AND ' . StoryVisibility::publicCondition('s'),
            ['story' => $storyId],
        );

        if ($visible === false || $visible === null) {
            return FollowOutcome::NotAvailable;
        }

        if ($this->isFollowing($user, $storyId)) {
            return FollowOutcome::AlreadyFollowing;
        }

        if ($this->count($user) >= self::LIMIT) {
            return FollowOutcome::ListFull;
        }

        // INSERT IGNORE: two quick clicks race past the check above, and the primary key then keeps just one row.
        $this->database->execute(
            'INSERT IGNORE INTO {prefix}story_follows (user_id, story_id, created_at) VALUES (:user, :story, :created)',
            ['user' => $user->id, 'story' => $storyId, 'created' => date('Y-m-d H:i:s')],
        );

        return FollowOutcome::Followed;
    }

    /**
     * Stops following one story. Reading progress and bookmarks are left alone.
     */
    public function stop(User $user, int $storyId): bool
    {
        return $this->database->execute(
            'DELETE FROM {prefix}story_follows WHERE user_id = :user AND story_id = :story',
            ['user' => $user->id, 'story' => $storyId],
        ) > 0;
    }

    public function stopAll(User $user): int
    {
        return $this->database->execute('DELETE FROM {prefix}story_follows WHERE user_id = :user', ['user' => $user->id]);
    }

    public function isFollowing(User $user, int $storyId): bool
    {
        $found = $this->database->fetchColumn(
            'SELECT story_id FROM {prefix}story_follows WHERE user_id = :user AND story_id = :story',
            ['user' => $user->id, 'story' => $storyId],
        );

        return $found !== false && $found !== null;
    }

    /**
     * Which of the given stories the reader follows, in one query.
     *
     * @param list<int> $storyIds
     * @return list<int>
     */
    public function followedAmong(User $user, array $storyIds): array
    {
        if ($storyIds === []) {
            return [];
        }

        $params = ['user' => $user->id];
        $marks = [];

        foreach (array_values($storyIds) as $index => $id) {
            $params['story' . $index] = $id;
            $marks[] = ':story' . $index;
        }

        return array_map(
            static fn (mixed $value): int => (int) $value,
            array_column($this->database->fetchAll(
                'SELECT story_id FROM {prefix}story_follows WHERE user_id = :user AND story_id IN (' . implode(', ', $marks) . ')',
                $params,
            ), 'story_id'),
        );
    }

    /**
     * Every row an account owns, hidden stories included. This is what the limit counts.
     */
    public function count(User $user): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}story_follows WHERE user_id = :user', ['user' => $user->id]);
    }

    /**
     * What is new in each of the given stories that the reader follows. A story the reader does not follow, or that
     * is no longer public, is left out.
     *
     * @param list<int> $storyIds
     * @return array<int, array{new: int, first_new: int|null, revised: bool}>
     */
    public function changes(User $user, array $storyIds): array
    {
        if ($storyIds === []) {
            return [];
        }

        $params = ['user' => $user->id];
        $marks = [];

        foreach (array_values($storyIds) as $index => $id) {
            $params['story' . $index] = $id;
            $marks[] = ':story' . $index;
        }

        $rows = $this->database->fetchAll(
            'SELECT s.id, ' . self::CHANGE_COLUMNS . ' FROM ' . self::CHANGE_SOURCE
            . ' WHERE f.user_id = :user AND f.story_id IN (' . implode(', ', $marks) . ') AND ' . StoryVisibility::publicCondition('s'),
            $params,
        );
        $found = [];

        foreach ($rows as $row) {
            $found[(int) $row['id']] = [
                'new' => (int) $row['new_chapters'],
                'first_new' => $row['first_new'] === null ? null : (int) $row['first_new'],
                'revised' => (int) $row['revised_chapters'] > 0,
            ];
        }

        return $found;
    }

    /**
     * The followed stories with something new, new chapters first and then revised ones, the newest change first.
     *
     * @return list<array{story: Story, new: int, first_new: int|null, revised: bool}>
     */
    public function updatesFor(User $user, int $limit, int $offset): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM (' . $this->updatesSource() . ') u WHERE u.new_chapters > 0 OR u.revised_chapters > 0
             ORDER BY (u.new_chapters > 0) DESC, COALESCE(u.latest_new, u.latest_revised) DESC, u.id DESC
             LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            ['user' => $user->id],
        );

        $stories = $this->stories->fromRows($rows);
        $list = [];

        foreach ($rows as $index => $row) {
            $list[] = [
                'story' => $stories[$index],
                'new' => (int) $row['new_chapters'],
                'first_new' => $row['first_new'] === null ? null : (int) $row['first_new'],
                'revised' => (int) $row['revised_chapters'] > 0,
            ];
        }

        return $list;
    }

    /**
     * How many followed stories have something new, counted by the same rule as the list.
     */
    public function countUpdates(User $user): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM (' . $this->updatesSource() . ') u WHERE u.new_chapters > 0 OR u.revised_chapters > 0',
            ['user' => $user->id],
        );
    }

    /**
     * Deletes everything an account follows. Account deletion calls this.
     */
    public function deleteForUser(int $userId): int
    {
        return $this->database->execute('DELETE FROM {prefix}story_follows WHERE user_id = :user', ['user' => $userId]);
    }

    private function updatesSource(): string
    {
        return 'SELECT s.*, ' . self::CHANGE_COLUMNS . ' FROM ' . self::CHANGE_SOURCE
            . ' WHERE f.user_id = :user AND ' . StoryVisibility::publicCondition('s');
    }

    /** The follow, the story, and the reader's progress through it, with the chapter they were last on. */
    private const CHANGE_SOURCE = '{prefix}story_follows f
        JOIN {prefix}stories s ON s.id = f.story_id
        LEFT JOIN {prefix}reading_progress p ON p.user_id = f.user_id AND p.story_id = f.story_id
        LEFT JOIN {prefix}chapters pc ON pc.id = p.chapter_id AND pc.story_id = f.story_id';

    /**
     * A new chapter is one a reader can open that was published after the last visit. A revised chapter is one the
     * reader has already opened (numbered no higher than where they stopped) that was published before the visit and
     * edited after it.
     */
    private const CHANGE_COLUMNS = "
        (SELECT COUNT(*) FROM {prefix}chapters nc WHERE nc.story_id = s.id AND nc.publication_state = 'published' AND nc.published_at > COALESCE(p.updated_at, f.created_at)) AS new_chapters,
        (SELECT MIN(nc.number) FROM {prefix}chapters nc WHERE nc.story_id = s.id AND nc.publication_state = 'published' AND nc.published_at > COALESCE(p.updated_at, f.created_at)) AS first_new,
        (SELECT MAX(nc.published_at) FROM {prefix}chapters nc WHERE nc.story_id = s.id AND nc.publication_state = 'published' AND nc.published_at > COALESCE(p.updated_at, f.created_at)) AS latest_new,
        (SELECT COUNT(*) FROM {prefix}chapters rc WHERE rc.story_id = s.id AND rc.publication_state = 'published' AND p.updated_at IS NOT NULL AND rc.number <= pc.number AND rc.published_at <= p.updated_at AND rc.updated_at > p.updated_at) AS revised_chapters,
        (SELECT MAX(rc.updated_at) FROM {prefix}chapters rc WHERE rc.story_id = s.id AND rc.publication_state = 'published' AND p.updated_at IS NOT NULL AND rc.number <= pc.number AND rc.published_at <= p.updated_at AND rc.updated_at > p.updated_at) AS latest_revised";
}
