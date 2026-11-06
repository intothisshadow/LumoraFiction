<?php

/**
 * Reader comments under a chapter: posting, editing, deleting, moderating, reporting and listing.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use DateTimeImmutable;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\Comment;
use LumoraFiction\Models\CommentStatus;
use LumoraFiction\Models\User;

/**
 * Every rule lives here and is enforced on the server: a hand-made request is held to the same checks as the form. A
 * comment is plain text and is escaped wherever it is printed; links are never made clickable. Rows are never trusted to
 * be tidy (no foreign keys), so every read joins to a published chapter of a published story.
 */
final class CommentService
{
    public const BODY_MAX = 3000;

    public const REASON_MAX = 300;

    /** Comments one account may post in a window, whatever their status, so a flood is slowed even if the spam guard is fooled. */
    public const RATE_LIMIT = 6;

    public const RATE_WINDOW_MINUTES = 10;

    public const DUPLICATE_WINDOW_MINUTES = 5;

    public const PER_PAGE = 20;

    /** The most comments one moderation action changes, so a single request cannot run unbounded. */
    public const BULK_LIMIT = 200;

    public const ACTIONS = ['approve', 'hide', 'spam', 'restore', 'delete'];

    public function __construct(
        private readonly Database $database,
        private readonly CommentSettings $settings,
        private readonly AuditLogService $audit,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    public function settings(): CommentSettings
    {
        return $this->settings;
    }

    // ---- who may do what ---------------------------------------------------------------------------------------------

    /** Whether this person could post at all right now (the site switch, their account, and no block); not whether a chapter is open. */
    public function mayComment(User $user): bool
    {
        return $this->settings->enabled() && $user->can(Capability::Comment) && $this->moderationOf($user->id) !== 'block';
    }

    public function isModerator(User $user): bool
    {
        return $user->can(Capability::ModerateComments);
    }

    /** Staff, or the writer of this story: the people who may hide or remove a comment under it. */
    public function mayManage(User $user, int $storyOwnerId): bool
    {
        return $this->isModerator($user) || ($user->can(Capability::WriteStories) && $user->id === $storyOwnerId);
    }

    /**
     * The chapter a comment may go under, or null when it is not open to readers (a draft, an unpublished story, one in the
     * trash, or missing).
     *
     * @return array{story_id: int, chapter_id: int, owner_id: int, number: int, title: string, story_title: string, story_slug: string}|null
     */
    public function target(int $chapterId): ?array
    {
        $row = $this->database->fetchOne(
            'SELECT c.id AS chapter_id, c.number, c.title, s.id AS story_id, s.owner_id, s.title AS story_title, s.slug AS story_slug
             FROM {prefix}chapters c JOIN {prefix}stories s ON s.id = c.story_id
             WHERE c.id = :id AND c.trashed_at IS NULL AND s.trashed_at IS NULL AND ' . StoryVisibility::publicChapterCondition('c', 's'),
            ['id' => $chapterId],
        );

        if ($row === null) {
            return null;
        }

        return [
            'story_id' => (int) $row['story_id'],
            'chapter_id' => (int) $row['chapter_id'],
            'owner_id' => (int) $row['owner_id'],
            'number' => (int) $row['number'],
            'title' => (string) $row['title'],
            'story_title' => (string) $row['story_title'],
            'story_slug' => (string) $row['story_slug'],
        ];
    }

    public function isClosed(int $storyId, int $chapterId): bool
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM {prefix}comment_closures WHERE story_id = :story AND chapter_id IN (0, :chapter)',
            ['story' => $storyId, 'chapter' => $chapterId],
        ) > 0;
    }

    /**
     * Turns comments off or on for a whole story (chapter 0) or one chapter. Allowed to the story's writer while the site
     * lets writers do it, and always to staff.
     *
     * @throws CommentException
     */
    public function setClosed(User $actor, int $storyId, int $chapterId, bool $closed, string $ipAddress = ''): bool
    {
        $owner = $this->database->fetchColumn('SELECT owner_id FROM {prefix}stories WHERE id = :id', ['id' => $storyId]);

        if ($owner === null || !$this->mayManage($actor, (int) $owner)) {
            throw new CommentException('You may not change that.');
        }

        if (!$this->isModerator($actor) && !$this->settings->authorMayToggle()) {
            throw new CommentException('Writers may not turn comments on or off on this site.');
        }

        if ($chapterId > 0 && (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}chapters WHERE id = :id AND story_id = :story', ['id' => $chapterId, 'story' => $storyId]) === 0) {
            throw new CommentException('That chapter is not part of the story.');
        }

        $isClosed = (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}comment_closures WHERE story_id = :story AND chapter_id = :chapter', ['story' => $storyId, 'chapter' => $chapterId]) > 0;

        if ($isClosed === $closed) {
            return false;
        }

        if ($closed) {
            $this->database->execute('INSERT INTO {prefix}comment_closures (story_id, chapter_id, created_at) VALUES (:story, :chapter, :created)', ['story' => $storyId, 'chapter' => $chapterId, 'created' => date('Y-m-d H:i:s')]);
        } else {
            $this->database->execute('DELETE FROM {prefix}comment_closures WHERE story_id = :story AND chapter_id = :chapter', ['story' => $storyId, 'chapter' => $chapterId]);
        }

        $this->audit->record($actor->id, $closed ? 'comment.closed' : 'comment.reopened', 'story', (string) $storyId, ['chapter' => $chapterId], $ipAddress);

        return true;
    }

    /**
     * What the story and chapter screens need to draw the on/off control, or null when this person has no such control.
     *
     * @return array{closed: bool, story: int, chapter: int}|null
     */
    public function control(User $actor, int $storyId, int $chapterId, int $ownerId): ?array
    {
        if (!$this->settings->enabled() || !$this->mayManage($actor, $ownerId) || (!$this->isModerator($actor) && !$this->settings->authorMayToggle())) {
            return null;
        }

        $closed = (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}comment_closures WHERE story_id = :story AND chapter_id = :chapter', ['story' => $storyId, 'chapter' => $chapterId]) > 0;

        return ['closed' => $closed, 'story' => $storyId, 'chapter' => $chapterId];
    }

    // ---- writing ------------------------------------------------------------------------------------------------------

    /**
     * Cleans a comment body. Plain text only.
     *
     * @throws CommentException
     */
    public function cleanBody(string $body): string
    {
        $body = trim(str_replace(["\r\n", "\r"], "\n", $body));

        if (!mb_check_encoding($body, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body) === 1) {
            throw new CommentException('The comment contains characters that cannot be saved.');
        }

        if (preg_match('/\S/u', $body) !== 1) {
            throw new CommentException('Write something first.');
        }

        if (mb_strlen($body, 'UTF-8') > self::BODY_MAX) {
            throw new CommentException('The comment is too long. Keep it under ' . number_format(self::BODY_MAX) . ' characters.');
        }

        return $body;
    }

    /**
     * Posts a comment. Nothing is stored for a chapter a reader cannot open, whatever the request says.
     *
     * @param bool $suspectedSpam the spam guard's verdict; such a comment is kept, marked as spam, not shown
     * @return array{comment: Comment, held: bool, spam: bool}
     * @throws CommentException with a sentence fit to show
     */
    public function post(User $user, int $chapterId, string $body, string $ipAddress = '', bool $suspectedSpam = false): array
    {
        if (!$this->settings->enabled()) {
            throw new CommentException('Comments are turned off on this site.');
        }

        if (!$user->can(Capability::Comment)) {
            throw new CommentException('You need to be signed in with a confirmed account to comment.');
        }

        $moderation = $this->moderationOf($user->id);

        if ($moderation === 'block') {
            throw new CommentException('Comments from your account are switched off.');
        }

        $target = $this->target($chapterId);

        if ($target === null) {
            throw new CommentException('That chapter is not open for comments.');
        }

        if ($this->isClosed($target['story_id'], $target['chapter_id'])) {
            throw new CommentException('Comments are turned off here.');
        }

        $body = $this->cleanBody($body);
        $filtered = $this->hooks?->applyFilters('comment_body', $body, $user, $target);

        if (is_string($filtered) && $filtered !== $body) {
            $body = $this->cleanBody($filtered);
        }

        $this->assertNotFlooding($user->id);
        $this->assertNotDuplicate($user->id, $chapterId, $body);

        $status = $this->statusFor($user, $target['owner_id'], $moderation, $suspectedSpam);
        $now = new DateTimeImmutable();

        $id = $this->database->insertGetId(
            'INSERT INTO {prefix}comments (story_id, chapter_id, user_id, body, status, report_count, created_at) VALUES (:story, :chapter, :user, :body, :status, 0, :created)',
            ['story' => $target['story_id'], 'chapter' => $target['chapter_id'], 'user' => $user->id, 'body' => $body, 'status' => $status->value, 'created' => $now->format('Y-m-d H:i:s')],
        );
        $comment = $this->find((int) $id) ?? throw new CommentException('The comment could not be saved.');

        $this->hooks?->doAction('comment_created', $comment, $user);

        return ['comment' => $comment, 'held' => $status === CommentStatus::Pending, 'spam' => $status === CommentStatus::Spam];
    }

    public function mayEdit(User $user, Comment $comment): bool
    {
        $minutes = $this->settings->editMinutes();

        return $minutes > 0
            && $comment->userId === $user->id
            && $user->can(Capability::Comment)
            && in_array($comment->status, [CommentStatus::Published, CommentStatus::Pending], true)
            && $comment->createdAt->getTimestamp() + $minutes * 60 >= time();
    }

    /**
     * A commenter changes their own words within the edit window; nobody else can, staff and the writer included.
     *
     * @throws CommentException
     */
    public function edit(User $user, int $commentId, string $body): Comment
    {
        $comment = $this->find($commentId);

        if ($comment === null || !$this->mayEdit($user, $comment)) {
            throw new CommentException('That comment can no longer be edited.');
        }

        $body = $this->cleanBody($body);

        if ($body !== $comment->body) {
            $this->database->execute('UPDATE {prefix}comments SET body = :body, edited_at = :edited WHERE id = :id', ['body' => $body, 'edited' => date('Y-m-d H:i:s'), 'id' => $comment->id]);
        }

        return $this->find($comment->id) ?? $comment;
    }

    /**
     * A commenter may delete their own comment at any time; the writer of the story and staff may delete any under it.
     *
     * @throws CommentException
     */
    public function deleteOne(User $actor, int $commentId, string $ipAddress = ''): void
    {
        $comment = $this->find($commentId);
        $owner = $comment === null ? null : $this->database->fetchColumn('SELECT owner_id FROM {prefix}stories WHERE id = :id', ['id' => $comment->storyId]);

        if ($comment === null || !($actor->id === $comment->userId || ($owner !== null && $this->mayManage($actor, (int) $owner)))) {
            throw new CommentException('That comment is not available.');
        }

        $this->removeRows([$comment->id]);

        if ($actor->id !== $comment->userId) {
            $this->audit->record($actor->id, 'comment.moderate', 'comment', (string) $comment->id, ['action' => 'delete', 'ids' => [$comment->id]], $ipAddress);
        }
    }

    // ---- moderation ---------------------------------------------------------------------------------------------------

    /**
     * Approves, hides, marks as spam, restores or deletes comments, limited to the ones the actor may manage. Approving or
     * restoring clears the reports against a comment, since a person has now looked at it.
     *
     * @param list<int> $ids
     * @return array{changed: int, skipped: int}
     * @throws CommentException
     */
    public function moderate(User $actor, string $action, array $ids, string $ipAddress = ''): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new CommentException('Choose what to do with the selected comments.');
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            throw new CommentException('Tick at least one comment first.');
        }

        if (count($ids) > self::BULK_LIMIT) {
            throw new CommentException('At most ' . self::BULK_LIMIT . ' comments can be changed at once.');
        }

        if (!$this->isModerator($actor) && !$actor->can(Capability::WriteStories)) {
            throw new CommentException('You may not moderate comments.');
        }

        [$marks, $params] = $this->marks($ids, 'mod_');
        $scope = '';

        if (!$this->isModerator($actor)) {
            $scope = ' AND s.owner_id = :scope_owner';
            $params['scope_owner'] = $actor->id;
        }

        $rows = $this->database->fetchAll(
            'SELECT c.* FROM {prefix}comments c JOIN {prefix}stories s ON s.id = c.story_id WHERE c.id IN (' . $marks . ')' . $scope,
            $params,
        );
        $comments = array_map($this->hydrate(...), $rows);

        if ($comments === []) {
            return ['changed' => 0, 'skipped' => count($ids)];
        }

        $target = match ($action) {
            'approve', 'restore' => CommentStatus::Published,
            'hide' => CommentStatus::Hidden,
            'spam' => CommentStatus::Spam,
            default => null,
        };
        $changedIds = [];

        $this->database->transaction(function () use ($comments, $target, $action, &$changedIds): void {
            if ($target === null) {
                $changedIds = array_map(static fn (Comment $comment): int => $comment->id, $comments);
                $this->removeRows($changedIds);

                return;
            }

            foreach ($comments as $comment) {
                $clears = $target === CommentStatus::Published;

                if ($comment->status === $target && !($clears && $comment->reportCount > 0)) {
                    continue;
                }

                $this->database->execute('UPDATE {prefix}comments SET status = :status' . ($clears ? ', report_count = 0' : '') . ' WHERE id = :id', ['status' => $target->value, 'id' => $comment->id]);

                if ($clears) {
                    $this->database->execute('DELETE FROM {prefix}comment_reports WHERE comment_id = :id', ['id' => $comment->id]);
                }

                $changedIds[] = $comment->id;
            }
        });

        if ($changedIds !== []) {
            $this->audit->record($actor->id, 'comment.moderate', 'comment', (string) $changedIds[0], ['action' => $action, 'ids' => $changedIds, 'count' => count($changedIds)], $ipAddress);
        }

        if ($target !== null) {
            foreach ($comments as $comment) {
                if (in_array($comment->id, $changedIds, true)) {
                    $this->hooks?->doAction('comment_status_changed', $this->find($comment->id) ?? $comment, $comment->status, $actor);
                }
            }
        }

        return ['changed' => count($changedIds), 'skipped' => count($ids) - count($changedIds)];
    }

    /**
     * A signed-in reader reports a published comment. One report each, never their own, and a comment reported often
     * enough is hidden until a moderator has looked at it.
     *
     * @throws CommentException
     */
    public function report(User $reporter, int $commentId, string $reason): bool
    {
        if (!$reporter->can(Capability::Comment)) {
            throw new CommentException('You need to be signed in to report a comment.');
        }

        $comment = $this->find($commentId);

        if ($comment === null || $comment->status !== CommentStatus::Published || $this->target($comment->chapterId) === null) {
            throw new CommentException('That comment is not available.');
        }

        if ($comment->userId === $reporter->id) {
            throw new CommentException('You cannot report your own comment. Delete it instead.');
        }

        $reason = trim(str_replace(["\r\n", "\r", "\n"], ' ', $reason));

        if (!mb_check_encoding($reason, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $reason) === 1) {
            throw new CommentException('The reason contains characters that cannot be saved.');
        }

        if (mb_strlen($reason, 'UTF-8') > self::REASON_MAX) {
            throw new CommentException('Keep the reason under ' . self::REASON_MAX . ' characters.');
        }

        $hidden = false;

        $added = $this->database->transaction(function () use ($comment, $reporter, $reason, &$hidden): bool {
            $inserted = $this->database->execute(
                'INSERT IGNORE INTO {prefix}comment_reports (comment_id, reporter_id, reason, created_at) VALUES (:comment, :reporter, :reason, :created)',
                ['comment' => $comment->id, 'reporter' => $reporter->id, 'reason' => $reason, 'created' => date('Y-m-d H:i:s')],
            );

            if ($inserted === 0) {
                return false;
            }

            $this->database->execute('UPDATE {prefix}comments SET report_count = report_count + 1 WHERE id = :id', ['id' => $comment->id]);
            $count = (int) $this->database->fetchColumn('SELECT report_count FROM {prefix}comments WHERE id = :id', ['id' => $comment->id]);

            if ($count >= $this->settings->reportThreshold()) {
                $this->database->execute("UPDATE {prefix}comments SET status = 'hidden' WHERE id = :id AND status = 'published'", ['id' => $comment->id]);
                $hidden = true;
            }

            return true;
        });

        if ($hidden) {
            $this->audit->record(null, 'comment.auto_hidden', 'comment', (string) $comment->id, ['threshold' => $this->settings->reportThreshold()]);
            $this->hooks?->doAction('comment_status_changed', $this->find($comment->id) ?? $comment, $comment->status, null);
        }

        return $added;
    }

    // ---- reading ------------------------------------------------------------------------------------------------------

    public function find(int $id): ?Comment
    {
        $row = $this->database->fetchOne('SELECT * FROM {prefix}comments WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /** Published comments on a chapter that a reader can open. */
    public function countForChapter(int $chapterId): int
    {
        return (int) $this->database->fetchColumn("SELECT COUNT(*) FROM {prefix}comments WHERE chapter_id = :chapter AND status = 'published'", ['chapter' => $chapterId]);
    }

    /**
     * @param list<int> $storyIds
     * @return array<int, int> published comments by story id
     */
    public function countsForStories(array $storyIds): array
    {
        if ($storyIds === []) {
            return [];
        }

        [$marks, $params] = $this->marks($storyIds, 'story_');
        $counts = [];

        foreach ($this->database->fetchAll("SELECT story_id, COUNT(*) AS total FROM {prefix}comments WHERE status = 'published' AND story_id IN (" . $marks . ') GROUP BY story_id', $params) as $row) {
            $counts[(int) $row['story_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * What one viewer sees under a chapter, oldest first: published comments for everyone, plus comments waiting for
     * approval for their own writer, for the story's writer and for staff. Hidden and spam comments never appear here.
     *
     * @return list<array{comment: Comment, name: string, is_author: bool, pending: bool}>
     */
    public function forChapter(int $chapterId, int $storyOwnerId, ?User $viewer, int $limit, int $offset): array
    {
        [$where, $params] = $this->publicWhere($chapterId, $storyOwnerId, $viewer);
        $rows = $this->database->fetchAll(
            'SELECT c.*, u.display_name FROM {prefix}comments c LEFT JOIN {prefix}users u ON u.id = c.user_id WHERE ' . $where
            . ' ORDER BY c.created_at, c.id LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            $params,
        );
        $items = [];

        foreach ($rows as $row) {
            $comment = $this->hydrate($row);
            // Worked out each time from the story's owner, never stored, so a changed owner or a deleted account stays right.
            $items[] = ['comment' => $comment, 'name' => (string) ($row['display_name'] ?? ''), 'is_author' => $comment->userId === $storyOwnerId, 'pending' => $comment->status === CommentStatus::Pending];
        }

        return $items;
    }

    public function countVisible(int $chapterId, int $storyOwnerId, ?User $viewer): int
    {
        [$where, $params] = $this->publicWhere($chapterId, $storyOwnerId, $viewer);

        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}comments c WHERE ' . $where, $params);
    }

    /**
     * Comments a person may see in the moderation list, with the story, chapter and commenter beside each.
     *
     * @param array{status?: string, story?: int, chapter?: int, user?: int, from?: string, to?: string, q?: string} $filters
     * @return list<array{comment: Comment, story_title: string, story_slug: string, owner_id: int, chapter_number: int, chapter_title: string, commenter: string, commenter_status: string, published: bool}>
     */
    public function search(User $actor, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->adminWhere($actor, $filters);

        $rows = $this->database->fetchAll(
            'SELECT c.*, s.title AS story_title, s.slug AS story_slug, s.owner_id, ch.number AS chapter_number, ch.title AS chapter_title,
                    u.display_name AS commenter, u.status AS commenter_status,
                    (s.publication_state = \'published\' AND ch.publication_state = \'published\') AS is_public
             FROM {prefix}comments c JOIN {prefix}stories s ON s.id = c.story_id
             LEFT JOIN {prefix}chapters ch ON ch.id = c.chapter_id LEFT JOIN {prefix}users u ON u.id = c.user_id
             WHERE ' . $where . ' ORDER BY c.created_at DESC, c.id DESC LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            $params,
        );
        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'comment' => $this->hydrate($row),
                'story_title' => (string) $row['story_title'],
                'story_slug' => (string) $row['story_slug'],
                'owner_id' => (int) $row['owner_id'],
                'chapter_number' => (int) ($row['chapter_number'] ?? 0),
                'chapter_title' => (string) ($row['chapter_title'] ?? ''),
                'commenter' => (string) ($row['commenter'] ?? ''),
                'commenter_status' => (string) ($row['commenter_status'] ?? ''),
                'published' => (int) ($row['is_public'] ?? 0) === 1,
            ];
        }

        return $result;
    }

    /**
     * @param array{status?: string, story?: int, chapter?: int, user?: int, from?: string, to?: string, q?: string} $filters
     */
    public function countMatching(User $actor, array $filters): int
    {
        [$where, $params] = $this->adminWhere($actor, $filters);

        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM {prefix}comments c JOIN {prefix}stories s ON s.id = c.story_id WHERE ' . $where,
            $params,
        );
    }

    /**
     * Ids of the comments a filter matches, up to a limit, for an action on "everything that matches".
     *
     * @param array{status?: string, story?: int, chapter?: int, user?: int, from?: string, to?: string, q?: string} $filters
     * @return list<int>
     */
    public function idsMatching(User $actor, array $filters, int $limit): array
    {
        [$where, $params] = $this->adminWhere($actor, $filters);

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->fetchAll('SELECT c.id FROM {prefix}comments c JOIN {prefix}stories s ON s.id = c.story_id WHERE ' . $where . ' ORDER BY c.created_at DESC, c.id DESC LIMIT ' . max(1, $limit), $params),
        );
    }

    /**
     * The number in each tab, for the same filters apart from the status.
     *
     * @param array{story?: int, chapter?: int, user?: int, from?: string, to?: string, q?: string} $filters
     * @return array{all: int, published: int, pending: int, hidden: int, spam: int, reported: int}
     */
    public function tabCounts(User $actor, array $filters): array
    {
        [$where, $params] = $this->adminWhere($actor, $filters);
        $row = $this->database->fetchOne(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(c.status = 'published'), 0) AS published,
                    COALESCE(SUM(c.status = 'pending'), 0) AS pending,
                    COALESCE(SUM(c.status = 'hidden'), 0) AS hidden,
                    COALESCE(SUM(c.status = 'spam'), 0) AS spam,
                    COALESCE(SUM(c.report_count > 0), 0) AS reported
             FROM {prefix}comments c JOIN {prefix}stories s ON s.id = c.story_id WHERE " . $where,
            $params,
        ) ?? [];

        return [
            'all' => (int) ($row['total'] ?? 0),
            'published' => (int) ($row['published'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'hidden' => (int) ($row['hidden'] ?? 0),
            'spam' => (int) ($row['spam'] ?? 0),
            'reported' => (int) ($row['reported'] ?? 0),
        ];
    }

    /** Comments waiting for a decision (pending, or reported), the number shown beside the menu entry and on the dashboard. */
    public function queueCount(User $actor): int
    {
        if (!$this->isModerator($actor) && !$actor->can(Capability::WriteStories)) {
            return 0;
        }

        [$where, $params] = $this->adminWhere($actor, []);

        return (int) $this->database->fetchColumn(
            "SELECT COUNT(*) FROM {prefix}comments c JOIN {prefix}stories s ON s.id = c.story_id WHERE (c.status = 'pending' OR (c.report_count > 0 AND c.status = 'published')) AND " . $where,
            $params,
        );
    }

    /**
     * Who reported each comment and why, for staff and the story's writer. The reporter is never shown to the commenter.
     *
     * @param list<int> $commentIds
     * @return array<int, list<array{name: string, reason: string, at: string}>>
     */
    public function reportsFor(array $commentIds): array
    {
        if ($commentIds === []) {
            return [];
        }

        [$marks, $params] = $this->marks($commentIds, 'rep_');
        $reports = [];

        foreach ($this->database->fetchAll(
            'SELECT r.comment_id, r.reason, r.created_at, u.display_name FROM {prefix}comment_reports r LEFT JOIN {prefix}users u ON u.id = r.reporter_id WHERE r.comment_id IN (' . $marks . ') ORDER BY r.id',
            $params,
        ) as $row) {
            $reports[(int) $row['comment_id']][] = ['name' => (string) ($row['display_name'] ?? ''), 'reason' => (string) $row['reason'], 'at' => (string) $row['created_at']];
        }

        return $reports;
    }

    /**
     * What a moderator sees about one commenter: how many comments they have, how many were held or marked as spam, and any hold.
     *
     * @return array{total: int, held: int, spam: int, moderation: string}
     */
    public function accountSummary(int $userId): array
    {
        $row = $this->database->fetchOne(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'pending'), 0) AS held, COALESCE(SUM(status = 'spam'), 0) AS spam FROM {prefix}comments WHERE user_id = :user",
            ['user' => $userId],
        ) ?? [];

        return ['total' => (int) ($row['total'] ?? 0), 'held' => (int) ($row['held'] ?? 0), 'spam' => (int) ($row['spam'] ?? 0), 'moderation' => $this->moderationOf($userId)];
    }

    /**
     * Holds (every future comment waits for approval), blocks (none can be posted) or clears an account. Staff only.
     *
     * @throws CommentException
     */
    public function setModeration(User $actor, int $userId, string $mode, string $ipAddress = ''): void
    {
        if (!$this->isModerator($actor)) {
            throw new CommentException('You may not do that.');
        }

        if (!in_array($mode, ['', 'hold', 'block'], true) || $userId < 1 || $userId === $actor->id) {
            throw new CommentException('That change is not possible.');
        }

        $this->database->execute(
            'INSERT INTO {prefix}comment_accounts (user_id, moderation, updated_at) VALUES (:user, :mode, :now) ON DUPLICATE KEY UPDATE moderation = :mode2, updated_at = :now2',
            ['user' => $userId, 'mode' => $mode, 'now' => date('Y-m-d H:i:s'), 'mode2' => $mode, 'now2' => date('Y-m-d H:i:s')],
        );
        $this->audit->record($actor->id, 'comment.account', 'user', (string) $userId, ['mode' => $mode], $ipAddress);
    }

    public function moderationOf(int $userId): string
    {
        $value = (string) ($this->database->fetchColumn('SELECT moderation FROM {prefix}comment_accounts WHERE user_id = :user', ['user' => $userId]) ?? '');

        return in_array($value, ['hold', 'block'], true) ? $value : '';
    }

    // ---- preferences --------------------------------------------------------------------------------------------------

    /**
     * @return array{notify_comments: bool, email_comments: bool, email_replies: bool}
     */
    public function preferences(int $userId): array
    {
        $row = $this->database->fetchOne('SELECT notify_comments, email_comments, email_replies FROM {prefix}comment_accounts WHERE user_id = :user', ['user' => $userId]);

        return $row === null
            ? ['notify_comments' => true, 'email_comments' => false, 'email_replies' => false]
            : ['notify_comments' => (int) $row['notify_comments'] === 1, 'email_comments' => (int) $row['email_comments'] === 1, 'email_replies' => (int) $row['email_replies'] === 1];
    }

    public function savePreferences(int $userId, bool $notify, bool $emailComments, bool $emailReplies): void
    {
        $now = date('Y-m-d H:i:s');
        $this->database->execute(
            'INSERT INTO {prefix}comment_accounts (user_id, notify_comments, email_comments, email_replies, updated_at) VALUES (:user, :n, :e, :r, :now)
             ON DUPLICATE KEY UPDATE notify_comments = :n2, email_comments = :e2, email_replies = :r2, updated_at = :now2',
            ['user' => $userId, 'n' => (int) $notify, 'e' => (int) $emailComments, 'r' => (int) $emailReplies, 'now' => $now, 'n2' => (int) $notify, 'e2' => (int) $emailComments, 'r2' => (int) $emailReplies, 'now2' => $now],
        );
    }

    /**
     * People who commented on a chapter and asked to hear when the writer answers, most recent first, never the writer.
     *
     * @return list<array{id: int, email: string, name: string}>
     */
    public function replyRecipients(int $chapterId, int $writerId, int $limit = 20): array
    {
        $rows = $this->database->fetchAll(
            "SELECT u.id, u.email, u.display_name FROM {prefix}users u JOIN {prefix}comment_accounts a ON a.user_id = u.id AND a.email_replies = 1
             WHERE u.status = 'active' AND u.id <> :writer AND u.id IN (SELECT user_id FROM {prefix}comments WHERE chapter_id = :chapter AND status = 'published')
             ORDER BY u.id LIMIT " . max(1, min(50, $limit)),
            ['writer' => $writerId, 'chapter' => $chapterId],
        );

        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'email' => (string) $row['email'], 'name' => (string) $row['display_name']], $rows);
    }

    // ---- cleanup and privacy ------------------------------------------------------------------------------------------

    /**
     * When an account is deleted its comments stay, shown under the neutral name; what is removed is what the person did
     * to others (their reports) and their own settings.
     */
    public function forgetUser(int $userId): void
    {
        $reported = $this->database->fetchAll('SELECT DISTINCT comment_id FROM {prefix}comment_reports WHERE reporter_id = :user', ['user' => $userId]);
        $this->database->execute('DELETE FROM {prefix}comment_reports WHERE reporter_id = :user', ['user' => $userId]);

        foreach ($reported as $row) {
            $this->database->execute(
                'UPDATE {prefix}comments SET report_count = (SELECT COUNT(*) FROM {prefix}comment_reports WHERE comment_id = :c1) WHERE id = :c2',
                ['c1' => (int) $row['comment_id'], 'c2' => (int) $row['comment_id']],
            );
        }

        $this->database->execute('DELETE FROM {prefix}comment_accounts WHERE user_id = :user', ['user' => $userId]);
    }

    /**
     * Everything a person has written, for their own data export.
     *
     * @return list<array{story: string, chapter: int, status: string, posted: string, edited: ?string, text: string}>
     */
    public function exportFor(int $userId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT c.*, s.title AS story_title, ch.number AS chapter_number FROM {prefix}comments c
             LEFT JOIN {prefix}stories s ON s.id = c.story_id LEFT JOIN {prefix}chapters ch ON ch.id = c.chapter_id
             WHERE c.user_id = :user ORDER BY c.id',
            ['user' => $userId],
        );

        return array_map(static fn (array $row): array => [
            'story' => (string) ($row['story_title'] ?? ''),
            'chapter' => (int) ($row['chapter_number'] ?? 0),
            'status' => (string) $row['status'],
            'posted' => (string) $row['created_at'],
            'edited' => $row['edited_at'] === null ? null : (string) $row['edited_at'],
            'text' => (string) $row['body'],
        ], $rows);
    }

    // ---- internals ----------------------------------------------------------------------------------------------------

    private function statusFor(User $user, int $storyOwnerId, string $moderation, bool $suspectedSpam): CommentStatus
    {
        if ($suspectedSpam) {
            return CommentStatus::Spam;
        }

        if ($moderation === 'hold') {
            return CommentStatus::Pending;
        }

        // The writer answering under their own story, and staff, are not held for approval.
        if ($user->id === $storyOwnerId || $this->isModerator($user) || $this->settings->trust() === CommentSettings::TRUST_OPEN) {
            return CommentStatus::Published;
        }

        $approved = (int) $this->database->fetchColumn("SELECT COUNT(*) FROM {prefix}comments WHERE user_id = :user AND status = 'published'", ['user' => $user->id]);

        return $approved > 0 ? CommentStatus::Published : CommentStatus::Pending;
    }

    /**
     * @throws CommentException
     */
    private function assertNotFlooding(int $userId): void
    {
        $since = date('Y-m-d H:i:s', time() - self::RATE_WINDOW_MINUTES * 60);

        if ((int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}comments WHERE user_id = :user AND created_at >= :since', ['user' => $userId, 'since' => $since]) >= self::RATE_LIMIT) {
            throw new CommentException('You are commenting very fast. Please wait a few minutes and try again.');
        }
    }

    /**
     * @throws CommentException
     */
    private function assertNotDuplicate(int $userId, int $chapterId, string $body): void
    {
        $since = date('Y-m-d H:i:s', time() - self::DUPLICATE_WINDOW_MINUTES * 60);

        if ((int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}comments WHERE user_id = :user AND chapter_id = :chapter AND body = :body AND created_at >= :since', ['user' => $userId, 'chapter' => $chapterId, 'body' => $body, 'since' => $since]) > 0) {
            throw new CommentException('You already posted that comment.');
        }
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function publicWhere(int $chapterId, int $storyOwnerId, ?User $viewer): array
    {
        $params = ['chapter' => $chapterId];
        $visible = "c.status = 'published'";

        if ($viewer !== null) {
            if ($this->isModerator($viewer) || $viewer->id === $storyOwnerId) {
                $visible = "c.status IN ('published', 'pending')";
            } else {
                $visible = "(c.status = 'published' OR (c.status = 'pending' AND c.user_id = :viewer))";
                $params['viewer'] = $viewer->id;
            }
        }

        return ['c.chapter_id = :chapter AND ' . $visible, $params];
    }

    /**
     * @param array{status?: string, story?: int, chapter?: int, user?: int, from?: string, to?: string, q?: string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function adminWhere(User $actor, array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        // The scope is in the query itself: a writer's list can never include another writer's story.
        if (!$this->isModerator($actor)) {
            $where[] = 's.owner_id = :scope_owner';
            $params['scope_owner'] = $actor->id;
        }

        $status = (string) ($filters['status'] ?? '');

        if ($status === 'reported') {
            $where[] = 'c.report_count > 0';
        } elseif (CommentStatus::tryFrom($status) !== null) {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }

        foreach (['story' => 'c.story_id', 'chapter' => 'c.chapter_id', 'user' => 'c.user_id'] as $key => $column) {
            if (($filters[$key] ?? 0) > 0) {
                $where[] = $column . ' = :f_' . $key;
                $params['f_' . $key] = (int) $filters[$key];
            }
        }

        foreach (['from' => ['>=', ' 00:00:00'], 'to' => ['<=', ' 23:59:59']] as $key => [$operator, $time]) {
            $date = (string) ($filters[$key] ?? '');

            if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date) === 1 && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                $where[] = 'c.created_at ' . $operator . ' :d_' . $key;
                $params['d_' . $key] = $date . $time;
            }
        }

        $text = trim((string) ($filters['q'] ?? ''));

        if ($text !== '') {
            $where[] = 'c.body LIKE :q';
            $params['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($text, 0, 100)) . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param list<int> $ids
     */
    private function removeRows(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        [$marks, $params] = $this->marks($ids, 'del_');
        $this->database->execute('DELETE FROM {prefix}comment_reports WHERE comment_id IN (' . $marks . ')', $params);
        $this->database->execute('DELETE FROM {prefix}comments WHERE id IN (' . $marks . ')', $params);
    }

    /**
     * @param list<int> $values
     * @return array{0: string, 1: array<string, int>}
     */
    private function marks(array $values, string $prefix): array
    {
        $params = [];

        foreach (array_values($values) as $index => $value) {
            $params[$prefix . $index] = $value;
        }

        return [implode(', ', array_map(static fn (string $key): string => ':' . $key, array_keys($params))), $params];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Comment
    {
        return new Comment(
            id: (int) $row['id'],
            storyId: (int) $row['story_id'],
            chapterId: (int) $row['chapter_id'],
            userId: (int) $row['user_id'],
            body: (string) $row['body'],
            status: CommentStatus::tryFrom((string) $row['status']) ?? CommentStatus::Hidden,
            reportCount: (int) $row['report_count'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            editedAt: $row['edited_at'] === null ? null : new DateTimeImmutable((string) $row['edited_at']),
        );
    }
}
