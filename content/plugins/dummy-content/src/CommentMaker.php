<?php

/**
 * Makes placeholder comments, replies and reports under the generated stories.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\DummyContent;

use LumoraFiction\Core\Database\Database;
use LumoraFiction\Services\CommentService;
use LumoraFiction\Services\StoryVisibility;
use RuntimeException;

/**
 * Rows are written in batches rather than through CommentService::post(), which rate-limits an account and refuses a
 * duplicate, so a run of thousands would be refused. What post() and report() decide is repeated here: a comment goes
 * only under a published chapter of a published story that is not closed, the writer of the story is never held, a
 * reply answers a published top-level comment on the same chapter, and a reader reports a comment once and never their
 * own. A change to those rules in the service means a change to this file too.
 */
final class CommentMaker
{
    private const BATCH = 200;

    /** The most chapters considered, newest first, so a huge archive does not have to fit in memory. */
    private const CHAPTER_LIMIT = 50000;

    /** The first comments take each state in turn, so even a small run shows every kind the moderation screen has. */
    private const FIRST_STATES = ['published', 'pending', 'hidden', 'spam', 'reported'];

    public function __construct(
        private readonly Database $database,
        private readonly Rows $rows,
        private readonly Tracker $tracker,
        private readonly ?CommentService $comments = null,
    ) {
    }

    /**
     * @param int $wanted comments in all, replies included
     * @return array{comments: int, replies: int, reports: int, closures: int}
     */
    public function make(int $wanted, Corpus $corpus, int $actorId): array
    {
        $result = ['comments' => 0, 'replies' => 0, 'reports' => 0, 'closures' => 0];

        if ($wanted === 0) {
            return $result;
        }

        $users = $this->tracker->ids(Tracker::KIND_USER);
        $commenters = $users === [] ? [$actorId] : $users;
        $chapters = $this->eligibleChapters();

        if ($chapters === []) {
            return $result;
        }

        [$open, $closed] = $this->closeSome($chapters, $corpus);
        $result['closures'] = $this->rows->insert('comment_closures', ['story_id', 'chapter_id', 'created_at'], $closed, null, true);

        if ($open === []) {
            return $result;
        }

        $replyBudget = $this->repliesAllowed() ? intdiv($wanted, 4) : 0;
        $topLevel = $wanted - $replyBudget;
        $threshold = $this->comments?->settings()->reportThreshold() ?? 3;
        $parents = [];
        $position = 0;

        for ($made = 0; $made < $topLevel; $made += self::BATCH) {
            $drafts = [];

            for ($i = min(self::BATCH, $topLevel - $made); $i > 0; $i--) {
                $drafts[] = $this->draft($corpus, $corpus->pickPopular($open), $commenters, $threshold, $position < count(self::FIRST_STATES) ? self::FIRST_STATES[$position] : null, null);
                $position++;
            }

            foreach ($this->store($drafts) as $stored) {
                $result['comments']++;
                $result['reports'] += $stored['reports'];

                if ($stored['status'] === 'published') {
                    $parents[] = $stored;
                }
            }
        }

        // The first comment is always published, so there is something to answer whenever anything was made.
        for ($made = 0; $parents !== [] && $made < $replyBudget; $made += self::BATCH) {
            $drafts = [];

            for ($i = min(self::BATCH, $replyBudget - $made); $i > 0; $i--) {
                $parent = $corpus->pickPopular($parents);
                $drafts[] = $this->draft($corpus, $parent, $commenters, $threshold, null, $parent);
            }

            foreach ($this->store($drafts) as $stored) {
                $result['replies']++;
                $result['reports'] += $stored['reports'];
            }
        }

        return $result;
    }

    private function repliesAllowed(): bool
    {
        return $this->comments === null || $this->comments->settings()->repliesAllowed();
    }

    /**
     * Published chapters of published stories the plugin made, with no comments-off row on the chapter or its story: the
     * places a reader could comment right now.
     *
     * @return list<array{chapter: int, story: int, owner: int, at: int}>
     */
    private function eligibleChapters(): array
    {
        $rows = $this->database->fetchAll(
            "SELECT c.id, c.story_id, s.owner_id, c.published_at
             FROM {prefix}chapters c
             JOIN {prefix}stories s ON s.id = c.story_id
             JOIN {prefix}dummy_content_items t ON t.kind = 'story' AND t.item_id = s.id
             WHERE " . StoryVisibility::publicChapterCondition('c', 's') . '
               AND c.trashed_at IS NULL AND s.trashed_at IS NULL AND c.published_at IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM {prefix}comment_closures x WHERE x.story_id = s.id AND x.chapter_id IN (0, c.id))
             ORDER BY c.id DESC LIMIT ' . self::CHAPTER_LIMIT,
        );

        return array_map(
            static fn (array $row): array => ['chapter' => (int) $row['id'], 'story' => (int) $row['story_id'], 'owner' => (int) $row['owner_id'], 'at' => (int) strtotime((string) $row['published_at'])],
            $rows,
        );
    }

    /**
     * Switches comments off for a share of the stories and of the remaining chapters, through the same closure rows the
     * story and chapter screens write.
     *
     * @param list<array{chapter: int, story: int, owner: int, at: int}> $chapters
     * @return array{0: list<array{chapter: int, story: int, owner: int, at: int}>, 1: list<list<mixed>>}
     */
    private function closeSome(array $chapters, Corpus $corpus): array
    {
        $now = date('Y-m-d H:i:s');
        $storyClosed = [];
        $open = [];
        $closed = [];

        foreach ($chapters as $chapter) {
            if (!isset($storyClosed[$chapter['story']])) {
                $storyClosed[$chapter['story']] = $corpus->chance(8);

                if ($storyClosed[$chapter['story']]) {
                    $closed[] = [$chapter['story'], 0, $now];
                }
            }

            if ($storyClosed[$chapter['story']]) {
                continue;
            }

            if ($corpus->chance(8)) {
                $closed[] = [$chapter['story'], $chapter['chapter'], $now];

                continue;
            }

            $open[] = $chapter;
        }

        return [$open, $closed];
    }

    /**
     * Decides everything about one comment. A reply is drafted from its parent, which is itself a stored comment with
     * the same chapter and story.
     *
     * @param array<string, mixed> $target a chapter, or the stored parent when answering
     * @param list<int> $commenters
     * @param array<string, mixed>|null $parent
     * @return array<string, mixed>
     */
    private function draft(Corpus $corpus, array $target, array $commenters, int $threshold, ?string $forced, ?array $parent): array
    {
        $owner = (int) $target['owner'];
        $user = $this->commenter($corpus, $commenters, $owner, $forced === null && $corpus->chance($parent === null ? 12 : 30), $forced !== null);
        $isWriter = $user === $owner;

        // The writer answering under their own story is never held, hidden or reported.
        $kind = $isWriter ? 'published' : ($forced ?? $this->roll($corpus));
        $reporters = array_values(array_diff($commenters, [$user]));

        if ($kind === 'reported' && $reporters === []) {
            $kind = 'published';
        }

        $reports = match (true) {
            $kind === 'reported' => min(count($reporters), $corpus->between(1, max(1, min(3, $threshold - 1)))),
            // A hidden comment is sometimes one the readers' reports hid.
            $kind === 'hidden' && $reporters !== [] && $corpus->chance(30) => min(count($reporters), $threshold),
            default => 0,
        };

        $chosen = $reports === 0 ? [] : $this->some($corpus, $reporters, $reports);
        $status = $kind === 'reported' ? 'published' : $kind;
        $earliest = $parent === null ? (int) $target['at'] : (int) $parent['at'] + 60;
        $at = min(time(), $earliest + $corpus->between(0, $parent === null ? max(0, time() - $earliest) : 3 * 86400));
        $edited = ($status === 'published' || $status === 'pending') && $corpus->chance(8) ? date('Y-m-d H:i:s', min(time(), $at + $corpus->between(60, 1800))) : null;

        return [
            'story' => (int) $target['story'],
            'chapter' => (int) $target['chapter'],
            'owner' => $owner,
            'user' => $user,
            'parent' => $parent === null ? null : (int) $parent['id'],
            'body' => $corpus->commentText(),
            'status' => $status,
            'reports' => count($chosen),
            'reporters' => $chosen,
            'at' => $at,
            'edited' => $edited,
        ];
    }

    /**
     * @param list<int> $commenters
     */
    private function commenter(Corpus $corpus, array $commenters, int $owner, bool $writer, bool $notWriter): int
    {
        if ($writer) {
            return $owner;
        }

        $user = $corpus->pick($commenters);

        // A forced state needs somebody other than the writer, who is never held or hidden.
        for ($try = 0; $notWriter && $user === $owner && $try < 8; $try++) {
            $user = $corpus->pick($commenters);
        }

        return $user;
    }

    private function roll(Corpus $corpus): string
    {
        $roll = $corpus->between(1, 100);

        return match (true) {
            $roll <= 62 => 'published',
            $roll <= 74 => 'pending',
            $roll <= 82 => 'hidden',
            $roll <= 88 => 'spam',
            default => 'reported',
        };
    }

    /**
     * @param list<int> $pool
     * @return list<int>
     */
    private function some(Corpus $corpus, array $pool, int $count): array
    {
        $chosen = [];

        for ($try = 0; count($chosen) < $count && $try < $count * 8; $try++) {
            $chosen[$corpus->pick($pool)] = true;
        }

        return array_keys($chosen);
    }

    /**
     * Writes one batch of drafts and the reports that go with them. Ids of a single multi-row insert come out in the
     * order of its rows, so the drafts are matched to the new ids by position.
     *
     * @param list<array<string, mixed>> $drafts
     * @return list<array{id: int, story: int, chapter: int, owner: int, at: int, status: string, reports: int}>
     */
    private function store(array $drafts): array
    {
        if ($drafts === []) {
            return [];
        }

        $before = $this->rows->maxId('comments');
        $rows = [];

        foreach ($drafts as $draft) {
            $rows[] = [$draft['story'], $draft['chapter'], $draft['user'], $draft['parent'], $draft['body'], $draft['status'], $draft['reports'], date('Y-m-d H:i:s', $draft['at']), $draft['edited']];
        }

        $this->rows->insert('comments', ['story_id', 'chapter_id', 'user_id', 'parent_id', 'body', 'status', 'report_count', 'created_at', 'edited_at'], $rows, self::BATCH);

        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->fetchAll('SELECT id FROM {prefix}comments WHERE id > :before ORDER BY id', ['before' => $before]),
        );

        if (count($ids) !== count($drafts)) {
            throw new RuntimeException('Another process wrote comments while the placeholder comments were being made. Remove the dummy content and try again.');
        }

        $reportRows = [];
        $stored = [];

        foreach ($drafts as $index => $draft) {
            foreach ($draft['reporters'] as $reporter) {
                $reportRows[] = [$ids[$index], $reporter, ($ids[$index] + $reporter) % 3 === 0 ? 'Not suitable for this chapter.' : '', date('Y-m-d H:i:s', min(time(), $draft['at'] + 600))];
            }

            $stored[] = ['id' => $ids[$index], 'story' => $draft['story'], 'chapter' => $draft['chapter'], 'owner' => $draft['owner'], 'at' => $draft['at'], 'status' => $draft['status'], 'reports' => count($draft['reporters'])];
        }

        $this->rows->insert('comment_reports', ['comment_id', 'reporter_id', 'reason', 'created_at'], $reportRows, null, true);

        return $stored;
    }
}
