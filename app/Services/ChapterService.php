<?php

/**
 * Adds, edits, orders, publishes and deletes the chapters of a story.
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

use DateTimeImmutable;
use LumoraFiction\Core\Admin\BulkResult;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Helpers\WordCounter;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\Chapter;
use LumoraFiction\Models\ChapterRevision;
use LumoraFiction\Models\ContentFormat;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\User;
use PDOException;

/**
 * Permission follows the story: whoever may change a story may change its chapters (see
 * StoryService::canManage). Chapter numbers are always 1 to n with no gaps, and the story's word
 * total is brought back in step after every change. Text is stored as written; the content renderer
 * sanitises it whenever it is shown.
 */
final class ChapterService
{
    public const TITLE_MAX = 200;

    public const CONTENT_MAX = 1000000;

    public const NOTES_MAX = 4000;

    public const END_NOTES_MAX = 4000;

    /** Large enough to move every number out of the way during a reorder. */
    private const RENUMBER_OFFSET = 1000000;

    private const NUMBER_ATTEMPTS = 3;

    /** A trashed chapter is parked at a number far outside the story's own 1 to n, which is also past the renumbering offset. */
    private const TRASH_NUMBER_BASE = 2000000;

    /** The actions bulk() accepts. */
    public const BULK_ACTIONS = ['publish', 'unpublish', 'trash', 'delete'];

    /**
     * While a bulk action runs, the stories whose word total and term counts still need refreshing, so that is done
     * once per story at the end and not once per chapter. Null outside a bulk action.
     *
     * @var array<int, true>|null
     */
    private ?array $deferredRefresh = null;

    public function __construct(
        private readonly Database $database,
        private readonly StoryService $stories,
        private readonly AuditLogService $audit,
        private readonly ?Configuration $config = null,
    ) {
    }

    /**
     * The chapter goes at the end as a draft.
     *
     * @param array<string, mixed> $input title, content, format, notes
     * @throws StoryPermissionException
     * @throws StoryValidationException
     */
    public function add(User $actor, int $storyId, array $input, string $ipAddress = ''): Chapter
    {
        $story = $this->stories->manageable($actor, $storyId);
        $values = $this->validated($input, null);

        $id = (int) $this->database->transaction(function () use ($actor, $story, $values, $ipAddress): int {
            $id = $this->insert($story->id, $values);
            $this->stories->refreshWordCount($story->id);
            $this->audit->record($actor->id, 'chapter.create', 'chapter', (string) $id, ['story' => $story->id], $ipAddress);

            return $id;
        });

        return $this->mustFind($id);
    }

    /**
     * Only the fields present in $input change.
     *
     * @param array<string, mixed> $input
     * @throws StoryPermissionException
     * @throws StoryValidationException
     */
    public function update(User $actor, int $chapterId, array $input, string $ipAddress = ''): Chapter
    {
        $chapter = $this->manageable($actor, $chapterId);
        $values = $this->validated($input, $chapter);

        $this->database->transaction(function () use ($actor, $chapter, $values, $ipAddress): void {
            // What is about to be replaced is kept first, only when something it holds actually changes.
            if ($values['title'] !== $chapter->title || $values['content'] !== $chapter->content || $values['format'] !== $chapter->format || $values['notes'] !== $chapter->notes || $values['end_notes'] !== $chapter->endNotes) {
                $this->keepRevision($chapter, $actor->id);
            }

            $this->database->execute(
                'UPDATE {prefix}chapters SET title = :title, content = :content, content_format = :format, notes = :notes, end_notes = :end_notes,
                 word_count = :words, updated_at = :updated WHERE id = :id',
                [
                    'title' => $values['title'],
                    'content' => $values['content'],
                    'format' => $values['format']->value,
                    'notes' => $values['notes'],
                    'end_notes' => $values['end_notes'],
                    'words' => WordCounter::count($values['content'], $values['format']),
                    'updated' => date('Y-m-d H:i:s'),
                    'id' => $chapter->id,
                ],
            );
            $this->stories->refreshWordCount($chapter->storyId);
            $this->audit->record($actor->id, 'chapter.update', 'chapter', (string) $chapter->id, ['story' => $chapter->storyId], $ipAddress);
        });

        return $this->mustFind($chapter->id);
    }

    /**
     * Sets the dates of a chapter that was written and published somewhere else first. The published date is
     * changed only when the chapter is already published, so this never publishes anything, and a date in the
     * future is ignored.
     *
     * @throws StoryPermissionException
     */
    public function applyDates(User $actor, int $chapterId, ?DateTimeImmutable $created, ?DateTimeImmutable $updated, ?DateTimeImmutable $published): void
    {
        $chapter = $this->manageable($actor, $chapterId);
        $now = new DateTimeImmutable();
        $created = $created === null || $created > $now ? null : $created;
        $updated = $updated === null || $updated > $now ? null : $updated;
        $published = $chapter->publishedAt === null || $published === null || $published > $now ? null : $published;

        $this->database->execute(
            'UPDATE {prefix}chapters SET created_at = :created, updated_at = :updated, published_at = :published WHERE id = :id',
            [
                'created' => ($created ?? $chapter->createdAt)->format('Y-m-d H:i:s'),
                'updated' => ($updated ?? $created ?? $chapter->updatedAt)->format('Y-m-d H:i:s'),
                'published' => ($published ?? $chapter->publishedAt)?->format('Y-m-d H:i:s'),
                'id' => $chapter->id,
            ],
        );
    }

    /**
     * Puts the chapters in the given order and renumbers them 1 to n. The list must name every chapter
     * of the story exactly once; anything else is rejected so a stale form cannot silently drop one.
     *
     * @param list<int> $orderedChapterIds
     * @throws StoryPermissionException
     * @throws StoryValidationException
     */
    public function reorder(User $actor, int $storyId, array $orderedChapterIds, string $ipAddress = ''): void
    {
        $story = $this->stories->manageable($actor, $storyId);

        $current = array_map('intval', array_column($this->database->fetchAll(
            'SELECT id FROM {prefix}chapters WHERE story_id = :story AND trashed_at IS NULL ORDER BY number',
            ['story' => $story->id],
        ), 'id'));

        $wanted = array_values(array_map('intval', $orderedChapterIds));
        $sameSet = count($wanted) === count($current) && count(array_unique($wanted)) === count($wanted) && array_diff($wanted, $current) === [];

        if (!$sameSet) {
            throw new StoryValidationException(['order' => 'The chapter list is out of date. Reload the page and try again.']);
        }

        if ($wanted === $current) {
            return;
        }

        $this->database->transaction(function () use ($actor, $story, $wanted, $ipAddress): void {
            // Two steps because (story, number) is unique: moving every chapter out of range first means no
            // chapter ever briefly shares a number with another.
            $this->database->execute(
                'UPDATE {prefix}chapters SET number = number + ' . self::RENUMBER_OFFSET . ' WHERE story_id = :story AND trashed_at IS NULL',
                ['story' => $story->id],
            );

            foreach ($wanted as $index => $id) {
                $this->database->execute(
                    'UPDATE {prefix}chapters SET number = :number WHERE id = :id AND story_id = :story',
                    ['number' => $index + 1, 'id' => $id, 'story' => $story->id],
                );
            }

            $this->touchStory($story->id);
            $this->audit->record($actor->id, 'chapter.reorder', 'story', (string) $story->id, [], $ipAddress);
        });
    }

    /**
     * Swaps a chapter with its neighbour. This is the keyboard- and no-script-friendly way to reorder, and it
     * goes through reorder() so the same checks and renumbering apply.
     *
     * @param string $direction "up" or "down"
     * @throws StoryPermissionException
     * @throws StoryValidationException when the direction is not recognised
     */
    public function move(User $actor, int $chapterId, string $direction, string $ipAddress = ''): void
    {
        $chapter = $this->manageable($actor, $chapterId);

        if ($direction !== 'up' && $direction !== 'down') {
            throw new StoryValidationException(['order' => 'Choose a direction to move the chapter.']);
        }

        $order = array_map('intval', array_column($this->database->fetchAll(
            'SELECT id FROM {prefix}chapters WHERE story_id = :story AND trashed_at IS NULL ORDER BY number',
            ['story' => $chapter->storyId],
        ), 'id'));

        $position = array_search($chapter->id, $order, true);

        if ($position === false) {
            return;
        }

        $target = $direction === 'up' ? $position - 1 : $position + 1;

        // Moving the first chapter up or the last one down changes nothing.
        if ($target < 0 || $target >= count($order)) {
            return;
        }

        [$order[$position], $order[$target]] = [$order[$target], $order[$position]];

        $this->reorder($actor, $chapter->storyId, $order, $ipAddress);
    }

    /**
     * Chapter totals for a page of stories in one query.
     *
     * @param list<int> $storyIds
     * @return array<int, array{total: int, published: int}> story id => counts; a story with no chapters is absent
     */
    public function countsForStories(array $storyIds): array
    {
        $storyIds = array_values(array_unique(array_filter($storyIds, static fn (int $id): bool => $id > 0)));

        if ($storyIds === []) {
            return [];
        }

        $params = [];

        foreach ($storyIds as $index => $id) {
            $params['story' . $index] = $id;
        }

        $counts = [];

        foreach ($this->database->fetchAll(
            "SELECT story_id, COUNT(*) AS total, SUM(CASE WHEN publication_state = 'published' THEN 1 ELSE 0 END) AS published
             FROM {prefix}chapters WHERE trashed_at IS NULL AND story_id IN (" . implode(', ', array_map(static fn (string $key): string => ':' . $key, array_keys($params))) . ') GROUP BY story_id',
            $params,
        ) as $row) {
            $counts[(int) $row['story_id']] = ['total' => (int) $row['total'], 'published' => (int) $row['published']];
        }

        return $counts;
    }

    /**
     * @throws StoryPermissionException
     */
    public function publish(User $actor, int $chapterId, string $ipAddress = ''): Chapter
    {
        $chapter = $this->manageable($actor, $chapterId);

        return $chapter->state === PublicationState::Published
            ? $chapter
            : $this->transition($chapter, PublicationState::Published, $actor, 'chapter.publish', $ipAddress);
    }

    /**
     * @throws StoryPermissionException
     */
    public function unpublish(User $actor, int $chapterId, string $ipAddress = ''): Chapter
    {
        $chapter = $this->manageable($actor, $chapterId);

        return $chapter->state === PublicationState::Published
            ? $this->transition($chapter, PublicationState::Unpublished, $actor, 'chapter.unpublish', $ipAddress)
            : $chapter;
    }

    /**
     * Applies one action to several chapters of one story, one at a time through the same methods as the single
     * actions, so each chapter is checked and audited on its own and numbering is complete after every one. The
     * story's word total and term counts are refreshed once at the end.
     *
     * @param list<int> $chapterIds at most BulkResult::LIMIT are handled
     * @throws StoryPermissionException when the story is not one the actor may manage
     * @throws StoryValidationException when the action is not one of BULK_ACTIONS
     */
    public function bulk(User $actor, int $storyId, string $action, array $chapterIds, string $ipAddress = ''): BulkResult
    {
        if (!in_array($action, self::BULK_ACTIONS, true)) {
            throw new StoryValidationException(['bulk_action' => 'Choose what to do with the selected chapters.']);
        }

        $story = $this->stories->manageable($actor, $storyId);
        $result = new BulkResult();
        $this->deferredRefresh = [];

        try {
            foreach (array_slice(array_values(array_unique($chapterIds)), 0, BulkResult::LIMIT) as $id) {
                $chapter = $this->find($id);

                // A chapter of another story is skipped here, so a story's address cannot be used to change it.
                if ($chapter === null || $chapter->storyId !== $story->id || $chapter->isTrashed()) {
                    $result->skip('Some of the chapters are not part of this story.');

                    continue;
                }

                try {
                    match ($action) {
                        'publish' => $this->publish($actor, $id, $ipAddress),
                        'unpublish' => $this->unpublish($actor, $id, $ipAddress),
                        'trash' => $this->trash($actor, $id, $ipAddress),
                        'delete' => $this->delete($actor, $id, $ipAddress),
                    };
                    $result->done();
                } catch (StoryPermissionException) {
                    $result->skip('Some of the chapters could not be changed by you.');
                }
            }
        } finally {
            $stories = array_keys($this->deferredRefresh);
            $this->deferredRefresh = null;

            foreach ($stories as $id) {
                $this->refreshStory($id, true);
            }
        }

        if ($result->doneCount() > 0) {
            $this->audit->record($actor->id, 'chapter.bulk', 'story', (string) $story->id, ['action' => $action, 'done' => $result->doneCount(), 'skipped' => $result->skippedCount()], $ipAddress);
        }

        return $result;
    }

    /**
     * Brings a story's word total and term counts back in step, or, during a bulk action, notes that it must be done once
     * the whole batch is through.
     */
    private function refreshStory(int $storyId, bool $wordCount): void
    {
        if ($this->deferredRefresh !== null) {
            $this->deferredRefresh[$storyId] = true;

            return;
        }

        if ($wordCount) {
            $this->stories->refreshWordCount($storyId);
        }

        $this->stories->refreshTermCounts($storyId);
    }

    /**
     * Later chapters move up so numbering stays complete.
     *
     * @throws StoryPermissionException
     */
    public function delete(User $actor, int $chapterId, string $ipAddress = ''): void
    {
        $this->remove($this->manageableIncludingTrashed($actor, $chapterId), $actor, $ipAddress);
    }

    /**
     * Moves a chapter to the trash. It leaves the story at once, a published chapter becomes unpublished, later chapters
     * move up, and the chapter and its earlier versions are kept so it can be restored. Bookmarks and reading places
     * pointing at it are dropped, as they are when a chapter is deleted.
     *
     * @throws StoryPermissionException
     */
    public function trash(User $actor, int $chapterId, string $ipAddress = ''): Chapter
    {
        $chapter = $this->manageable($actor, $chapterId);

        $this->database->transaction(function () use ($actor, $chapter, $ipAddress): void {
            $now = date('Y-m-d H:i:s');
            $state = $chapter->state === PublicationState::Published ? PublicationState::Unpublished : $chapter->state;

            // Moved out of the story's numbering first, so closing the gap below can never meet it.
            $this->database->execute(
                'UPDATE {prefix}chapters SET trashed_at = :trashed, number = :parked, publication_state = :state, updated_at = :updated WHERE id = :id',
                ['trashed' => $now, 'parked' => self::TRASH_NUMBER_BASE + $chapter->id, 'state' => $state->value, 'updated' => $now, 'id' => $chapter->id],
            );
            $this->database->execute('DELETE FROM {prefix}bookmarks WHERE chapter_id = :id', ['id' => $chapter->id]);
            $this->database->execute('DELETE FROM {prefix}reading_progress WHERE chapter_id = :id', ['id' => $chapter->id]);
            $this->database->execute(
                'UPDATE {prefix}chapters SET number = number - 1 WHERE story_id = :story AND number > :number AND trashed_at IS NULL ORDER BY number ASC',
                ['story' => $chapter->storyId, 'number' => $chapter->number],
            );
            $this->refreshStory($chapter->storyId, true);
            $this->audit->record($actor->id, 'chapter.trash', 'chapter', (string) $chapter->id, ['story' => $chapter->storyId, 'number' => $chapter->number], $ipAddress);
        });

        return $this->mustFind($chapter->id);
    }

    /**
     * Removes a chapter that is in the trash for good; one that is not is refused, so a stale or forged request from the
     * trash screen cannot delete a live chapter.
     *
     * @throws StoryPermissionException
     */
    public function purge(User $actor, int $chapterId, string $ipAddress = ''): void
    {
        $chapter = $this->manageableIncludingTrashed($actor, $chapterId);

        if (!$chapter->isTrashed()) {
            throw new StoryPermissionException('That chapter is not in the trash.');
        }

        $this->remove($chapter, $actor, $ipAddress);
    }

    /**
     * Brings a chapter back from the trash as a draft at the end of its story. Its story must not be in the trash itself.
     *
     * @throws StoryPermissionException
     * @throws StoryValidationException when the story is in the trash
     */
    public function restore(User $actor, int $chapterId, string $ipAddress = ''): Chapter
    {
        $chapter = $this->manageableIncludingTrashed($actor, $chapterId);

        if (!$chapter->isTrashed()) {
            return $chapter;
        }

        if ($this->stories->find($chapter->storyId)?->isTrashed() === true) {
            throw new StoryValidationException(['chapter' => 'Restore the story first.']);
        }

        $this->database->transaction(function () use ($actor, $chapter, $ipAddress): void {
            $next = (int) $this->database->fetchColumn(
                'SELECT COALESCE(MAX(number), 0) + 1 FROM {prefix}chapters WHERE story_id = :story AND trashed_at IS NULL',
                ['story' => $chapter->storyId],
            );
            $this->database->execute(
                'UPDATE {prefix}chapters SET trashed_at = NULL, number = :number, updated_at = :updated WHERE id = :id',
                ['number' => $next, 'updated' => date('Y-m-d H:i:s'), 'id' => $chapter->id],
            );
            $this->stories->refreshWordCount($chapter->storyId);
            $this->audit->record($actor->id, 'chapter.restore', 'chapter', (string) $chapter->id, ['story' => $chapter->storyId], $ipAddress);
        });

        return $this->mustFind($chapter->id);
    }

    /**
     * Chapters in the trash that the person may restore: those of their own stories, or with $all and the right to edit
     * every story, anyone's. Each comes with the title of its story and whether that story is in the trash too.
     *
     * @return list<array{chapter: Chapter, story_id: int, story_title: string, story_trashed: bool}> most recently trashed first
     */
    public function listTrashed(User $actor, bool $all = false, int $limit = 100): array
    {
        if (!$actor->can(Capability::WriteStories)) {
            return [];
        }

        $everyone = $all && $actor->can(Capability::EditAnyStory);
        $rows = $this->database->fetchAll(
            'SELECT c.*, s.title AS story_title, s.trashed_at AS story_trashed_at FROM {prefix}chapters c JOIN {prefix}stories s ON s.id = c.story_id
             WHERE c.trashed_at IS NOT NULL' . ($everyone ? '' : ' AND s.owner_id = :owner') . ' ORDER BY c.trashed_at DESC, c.id DESC LIMIT ' . max(1, min(200, $limit)),
            $everyone ? [] : ['owner' => $actor->id],
        );

        return array_map(fn (array $row): array => [
            'chapter' => $this->hydrate($row),
            'story_id' => (int) $row['story_id'],
            'story_title' => (string) $row['story_title'],
            'story_trashed' => $row['story_trashed_at'] !== null,
        ], $rows);
    }

    /**
     * Permanently removes the trashed chapters kept longer than the retention period; zero days keeps them.
     *
     * @return int how many were removed
     */
    public function purgeExpiredTrash(): int
    {
        $days = $this->config === null ? RetentionSettings::DEFAULT_TRASH_DAYS : RetentionSettings::trashDays($this->config);

        if ($days < 1) {
            return 0;
        }

        $removed = 0;

        foreach ($this->database->fetchAll(
            'SELECT * FROM {prefix}chapters WHERE trashed_at IS NOT NULL AND trashed_at < :cutoff ORDER BY trashed_at LIMIT 200',
            ['cutoff' => date('Y-m-d H:i:s', time() - $days * 86400)],
        ) as $row) {
            $this->remove($this->hydrate($row), null, '');
            $removed++;
        }

        return $removed;
    }

    /**
     * Earlier versions of a chapter, newest first, without their text.
     *
     * @return list<ChapterRevision>
     * @throws StoryPermissionException
     */
    public function revisions(User $actor, int $chapterId): array
    {
        $chapter = $this->manageable($actor, $chapterId);

        return array_map(
            fn (array $row): ChapterRevision => $this->hydrateRevision($row),
            $this->database->fetchAll('SELECT * FROM {prefix}chapter_revisions WHERE chapter_id = :chapter ORDER BY id DESC', ['chapter' => $chapter->id]),
        );
    }

    public function countRevisions(int $chapterId): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}chapter_revisions WHERE chapter_id = :chapter', ['chapter' => $chapterId]);
    }

    /**
     * @throws StoryPermissionException when the chapter, or the version of it, is not there or not the caller's to see
     */
    public function revision(User $actor, int $chapterId, int $revisionId): ChapterRevision
    {
        $chapter = $this->manageable($actor, $chapterId);
        $row = $this->database->fetchOne('SELECT * FROM {prefix}chapter_revisions WHERE id = :id AND chapter_id = :chapter', ['id' => $revisionId, 'chapter' => $chapter->id]);

        if ($row === null) {
            throw new StoryPermissionException('That earlier version is not available.');
        }

        return $this->hydrateRevision($row);
    }

    /**
     * Puts an earlier version back as the chapter's text. The text it replaces is kept as a version too, so going back
     * can itself be undone. The chapter keeps its state, so a published chapter shows the older text straight away.
     *
     * @throws StoryPermissionException
     */
    public function restoreRevision(User $actor, int $chapterId, int $revisionId, string $ipAddress = ''): Chapter
    {
        $chapter = $this->manageable($actor, $chapterId);
        $revision = $this->revision($actor, $chapterId, $revisionId);

        $this->database->transaction(function () use ($actor, $chapter, $revision, $ipAddress): void {
            $this->keepRevision($chapter, $actor->id);
            $this->database->execute(
                'UPDATE {prefix}chapters SET title = :title, content = :content, content_format = :format, notes = :notes, end_notes = :end_notes,
                 word_count = :words, updated_at = :updated WHERE id = :id',
                [
                    'title' => $revision->title,
                    'content' => $revision->content,
                    'format' => $revision->format->value,
                    'notes' => $revision->notes,
                    'end_notes' => $revision->endNotes,
                    'words' => WordCounter::count($revision->content, $revision->format),
                    'updated' => date('Y-m-d H:i:s'),
                    'id' => $chapter->id,
                ],
            );
            $this->stories->refreshWordCount($chapter->storyId);
            $this->audit->record($actor->id, 'chapter.revision_restore', 'chapter', (string) $chapter->id, ['story' => $chapter->storyId, 'revision' => $revision->id], $ipAddress);
        });

        return $this->mustFind($chapter->id);
    }

    /**
     * Saves the chapter as it stands, then drops the oldest versions beyond the number the administrator keeps.
     */
    private function keepRevision(Chapter $chapter, ?int $userId): void
    {
        $this->database->execute(
            'INSERT INTO {prefix}chapter_revisions (chapter_id, title, content, content_format, notes, end_notes, word_count, created_by, created_at)
             VALUES (:chapter, :title, :content, :format, :notes, :end_notes, :words, :user, :created)',
            [
                'chapter' => $chapter->id,
                'title' => $chapter->title,
                'content' => $chapter->content,
                'format' => $chapter->format->value,
                'notes' => $chapter->notes,
                'end_notes' => $chapter->endNotes,
                'words' => $chapter->wordCount,
                'user' => $userId,
                'created' => date('Y-m-d H:i:s'),
            ],
        );

        $kept = $this->config === null ? RetentionSettings::DEFAULT_REVISIONS : RetentionSettings::revisionsKept($this->config);

        foreach (array_column($this->database->fetchAll(
            'SELECT id FROM {prefix}chapter_revisions WHERE chapter_id = :chapter ORDER BY id DESC LIMIT 1000 OFFSET ' . $kept,
            ['chapter' => $chapter->id],
        ), 'id') as $old) {
            $this->database->execute('DELETE FROM {prefix}chapter_revisions WHERE id = :id', ['id' => (int) $old]);
        }
    }

    /**
     * Deletes a chapter and its earlier versions. $actor is null when the retention period does the removing. A chapter
     * that is in the trash is outside the story's numbering, so only a live one closes a gap.
     */
    private function remove(Chapter $chapter, ?User $actor, string $ipAddress): void
    {
        $this->database->transaction(function () use ($actor, $chapter, $ipAddress): void {
            $this->database->execute('DELETE FROM {prefix}chapters WHERE id = :id', ['id' => $chapter->id]);
            $this->database->execute('DELETE FROM {prefix}chapter_revisions WHERE chapter_id = :id', ['id' => $chapter->id]);
            $this->database->execute('DELETE FROM {prefix}bookmarks WHERE chapter_id = :id', ['id' => $chapter->id]);
            $this->database->execute('DELETE FROM {prefix}reading_progress WHERE chapter_id = :id', ['id' => $chapter->id]);
            $this->database->execute('DELETE r FROM {prefix}comment_reports r JOIN {prefix}comments m ON m.id = r.comment_id WHERE m.chapter_id = :id', ['id' => $chapter->id]);
            $this->database->execute('DELETE FROM {prefix}comments WHERE chapter_id = :id', ['id' => $chapter->id]);
            $this->database->execute('DELETE FROM {prefix}comment_closures WHERE chapter_id = :id', ['id' => $chapter->id]);

            if (!$chapter->isTrashed()) {
                $this->database->execute(
                    'UPDATE {prefix}chapters SET number = number - 1 WHERE story_id = :story AND number > :number AND trashed_at IS NULL ORDER BY number ASC',
                    ['story' => $chapter->storyId, 'number' => $chapter->number],
                );
            }

            $this->refreshStory($chapter->storyId, true);
            $this->audit->record($actor?->id, $actor === null ? 'chapter.purge' : 'chapter.delete', 'chapter', (string) $chapter->id, ['story' => $chapter->storyId, 'number' => $chapter->number], $ipAddress);
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateRevision(array $row): ChapterRevision
    {
        return new ChapterRevision(
            id: (int) $row['id'],
            chapterId: (int) $row['chapter_id'],
            title: (string) $row['title'],
            content: (string) $row['content'],
            format: ContentFormat::tryFrom((string) $row['content_format']) ?? ContentFormat::Plain,
            notes: (string) $row['notes'],
            wordCount: (int) $row['word_count'],
            createdBy: $row['created_by'] === null ? null : (int) $row['created_by'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            endNotes: (string) ($row['end_notes'] ?? ''),
        );
    }

    public function find(int $id): ?Chapter
    {
        $row = $this->database->fetchOne('SELECT * FROM {prefix}chapters WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Every chapter of a story, in any state, for someone who may change the story.
     *
     * @return list<Chapter>
     * @throws StoryPermissionException
     */
    public function listForWriter(User $actor, int $storyId): array
    {
        $story = $this->stories->manageable($actor, $storyId);

        return $this->hydrateAll($this->database->fetchAll(
            'SELECT * FROM {prefix}chapters WHERE story_id = :story AND trashed_at IS NULL ORDER BY number',
            ['story' => $story->id],
        ));
    }

    /**
     * Every chapter of several stories in one query, for an export whose caller has already checked who may read them.
     * Chapters in the trash are left out.
     *
     * @param list<int> $storyIds
     * @return array<int, list<Chapter>> story id => its chapters in order
     */
    public function forExport(array $storyIds): array
    {
        if ($storyIds === []) {
            return [];
        }

        $grouped = [];

        foreach ($this->hydrateAll($this->database->fetchAll(
            'SELECT * FROM {prefix}chapters WHERE story_id IN (' . implode(',', array_map('intval', $storyIds)) . ') AND trashed_at IS NULL ORDER BY story_id, number',
        )) as $chapter) {
            $grouped[$chapter->storyId][] = $chapter;
        }

        return $grouped;
    }

    /**
     * The chapters a visitor may read, which is none unless the story itself is public.
     *
     * @return list<Chapter>
     */
    public function listPublic(int $storyId): array
    {
        return $this->hydrateAll($this->database->fetchAll(
            'SELECT c.* FROM {prefix}chapters c JOIN {prefix}stories s ON s.id = c.story_id
             WHERE c.story_id = :story AND ' . StoryVisibility::publicChapterCondition('c', 's') . ' ORDER BY c.number',
            ['story' => $storyId],
        ));
    }

    public function findPublic(int $storyId, int $number): ?Chapter
    {
        $row = $this->database->fetchOne(
            'SELECT c.* FROM {prefix}chapters c JOIN {prefix}stories s ON s.id = c.story_id
             WHERE c.story_id = :story AND c.number = :number AND ' . StoryVisibility::publicChapterCondition('c', 's'),
            ['story' => $storyId, 'number' => $number],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @throws StoryPermissionException when the chapter is missing or its story may not be changed by the caller
     */
    public function manageable(User $actor, int $chapterId): Chapter
    {
        $chapter = $this->find($chapterId);

        // A chapter in the trash is changed only by restoring it or deleting it for good.
        if ($chapter === null || $chapter->isTrashed()) {
            throw new StoryPermissionException('That chapter is not available.');
        }

        $this->stories->manageable($actor, $chapter->storyId);

        return $chapter;
    }

    /**
     * Like manageable(), but also for a chapter in the trash, and for one whose story is.
     *
     * @throws StoryPermissionException
     */
    public function manageableIncludingTrashed(User $actor, int $chapterId): Chapter
    {
        $chapter = $this->find($chapterId);

        if ($chapter === null) {
            throw new StoryPermissionException('That chapter is not available.');
        }

        $this->stories->manageableIncludingTrashed($actor, $chapter->storyId);

        return $chapter;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{title: string, content: string, format: ContentFormat, notes: string, end_notes: string}
     */
    private function validated(array $input, ?Chapter $existing): array
    {
        $errors = [];

        $title = array_key_exists('title', $input) ? $input['title'] : ($existing->title ?? '');
        $title = is_string($title) ? trim($title) : null;

        if ($title === null || !mb_check_encoding($title, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $title) === 1) {
            $errors['title'] = 'This contains characters that are not allowed.';
            $title = '';
        } elseif (mb_strlen($title, 'UTF-8') > self::TITLE_MAX) {
            $errors['title'] = 'Keep the title to ' . self::TITLE_MAX . ' characters or fewer.';
        }

        $content = array_key_exists('content', $input) ? $input['content'] : ($existing->content ?? '');

        if (!is_string($content) || !mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            $errors['content'] = 'The text contains characters that are not allowed.';
            $content = '';
        } elseif (mb_strlen($content, 'UTF-8') > self::CONTENT_MAX) {
            $errors['content'] = 'This chapter is too long to save in one piece. Split it into two chapters.';
        }

        $formatInput = array_key_exists('format', $input) ? $input['format'] : ($existing->format ?? ContentFormat::Html)->value;
        $format = is_string($formatInput) ? ContentFormat::tryFrom($formatInput) : null;

        if ($format === null) {
            $errors['format'] = 'Choose how the text is written.';
        }

        $notes = array_key_exists('notes', $input) ? $input['notes'] : ($existing->notes ?? '');
        $notes = is_string($notes) ? trim(str_replace(["\r\n", "\r"], "\n", $notes)) : null;

        if ($notes === null || !mb_check_encoding($notes, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $notes) === 1) {
            $errors['notes'] = 'This contains characters that are not allowed.';
            $notes = '';
        } elseif (mb_strlen($notes, 'UTF-8') > self::NOTES_MAX) {
            $errors['notes'] = 'Keep the notes to ' . self::NOTES_MAX . ' characters or fewer.';
        }

        $endNotes = array_key_exists('end_notes', $input) ? $input['end_notes'] : ($existing->endNotes ?? '');
        $endNotes = is_string($endNotes) ? trim(str_replace(["\r\n", "\r"], "\n", $endNotes)) : null;

        if ($endNotes === null || !mb_check_encoding($endNotes, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $endNotes) === 1) {
            $errors['end_notes'] = 'This contains characters that are not allowed.';
            $endNotes = '';
        } elseif (mb_strlen($endNotes, 'UTF-8') > self::END_NOTES_MAX) {
            $errors['end_notes'] = 'Keep the end notes to ' . self::END_NOTES_MAX . ' characters or fewer.';
        }

        if ($errors !== []) {
            throw new StoryValidationException($errors);
        }

        return ['title' => $title, 'content' => $content, 'format' => $format ?? ContentFormat::Html, 'notes' => $notes, 'end_notes' => $endNotes];
    }

    /**
     * @param array{title: string, content: string, format: ContentFormat, notes: string, end_notes: string} $values
     */
    private function insert(int $storyId, array $values): int
    {
        $now = date('Y-m-d H:i:s');

        for ($attempt = 1; ; $attempt++) {
            $next = (int) $this->database->fetchColumn(
                'SELECT COALESCE(MAX(number), 0) + 1 FROM {prefix}chapters WHERE story_id = :story AND trashed_at IS NULL',
                ['story' => $storyId],
            );

            try {
                return (int) $this->database->insertGetId(
                    'INSERT INTO {prefix}chapters (story_id, number, title, content, content_format, notes, end_notes, word_count,
                     publication_state, published_at, created_at, updated_at)
                     VALUES (:story, :number, :title, :content, :format, :notes, :end_notes, :words, :state, NULL, :created, :updated)',
                    [
                        'story' => $storyId,
                        'number' => $next,
                        'title' => $values['title'],
                        'content' => $values['content'],
                        'format' => $values['format']->value,
                        'notes' => $values['notes'],
                        'end_notes' => $values['end_notes'],
                        'words' => WordCounter::count($values['content'], $values['format']),
                        'state' => PublicationState::Draft->value,
                        'created' => $now,
                        'updated' => $now,
                    ],
                );
            } catch (PDOException $exception) {
                // Two chapters added at the same moment can pick the same number; the unique key stops the second, which retries.
                if (($exception->errorInfo[1] ?? null) !== 1062 || $attempt >= self::NUMBER_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }

    private function transition(Chapter $chapter, PublicationState $to, User $actor, string $auditAction, string $ipAddress): Chapter
    {
        $this->database->transaction(function () use ($chapter, $to, $actor, $auditAction, $ipAddress): void {
            $now = date('Y-m-d H:i:s');

            $this->database->execute(
                'UPDATE {prefix}chapters SET publication_state = :state, published_at = :published, updated_at = :updated WHERE id = :id',
                [
                    'state' => $to->value,
                    'published' => $to === PublicationState::Published && $chapter->publishedAt === null ? $now : $chapter->publishedAt?->format('Y-m-d H:i:s'),
                    'updated' => $now,
                    'id' => $chapter->id,
                ],
            );
            $this->touchStory($chapter->storyId);
            $this->refreshStory($chapter->storyId, false);
            $this->audit->record($actor->id, $auditAction, 'chapter', (string) $chapter->id, ['story' => $chapter->storyId, 'from' => $chapter->state->value, 'to' => $to->value], $ipAddress);
        });

        return $this->mustFind($chapter->id);
    }

    private function touchStory(int $storyId): void
    {
        $this->database->execute('UPDATE {prefix}stories SET updated_at = :updated WHERE id = :id', ['updated' => date('Y-m-d H:i:s'), 'id' => $storyId]);
    }

    private function mustFind(int $id): Chapter
    {
        return $this->find($id) ?? throw new StoryPermissionException('That chapter is not available.');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Chapter>
     */
    private function hydrateAll(array $rows): array
    {
        return array_map(fn (array $row): Chapter => $this->hydrate($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Chapter
    {
        return new Chapter(
            id: (int) $row['id'],
            storyId: (int) $row['story_id'],
            number: (int) $row['number'],
            title: (string) $row['title'],
            content: (string) $row['content'],
            format: ContentFormat::tryFrom((string) $row['content_format']) ?? ContentFormat::Plain,
            notes: (string) $row['notes'],
            wordCount: (int) $row['word_count'],
            // An unrecognised state counts as a draft so a corrupt value fails closed rather than going public.
            state: PublicationState::tryFrom((string) $row['publication_state']) ?? PublicationState::Draft,
            publishedAt: is_string($row['published_at']) && $row['published_at'] !== '' ? new DateTimeImmutable($row['published_at']) : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
            trashedAt: is_string($row['trashed_at'] ?? null) && $row['trashed_at'] !== '' ? new DateTimeImmutable($row['trashed_at']) : null,
            endNotes: (string) ($row['end_notes'] ?? ''),
        );
    }
}
