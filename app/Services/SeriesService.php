<?php

/**
 * Series: an author's ordered sequence of stories.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use DateTimeImmutable;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Helpers\Slug;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\Series;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\User;
use LumoraFiction\Models\WorkStatus;

/**
 * Only the series owner and staff who may edit any story manage a series, and a story can be added only by someone who may
 * manage that story, and only to a series of the same author. Every public read starts from the story-visibility rule, so
 * a draft, pending or unpublished story is never listed in a series, counted in it or named in a "part of" line. Deleting a
 * series, or a story, removes only the link: the stories and the rest of the series stay.
 */
final class SeriesService
{
    public const TITLE_MAX = 200;

    public const SUMMARY_MAX = 2000;

    /** Control, zero-width and text-direction characters: invisible or reordering, so a title could hide behind them. */
    private const FORBIDDEN = '/[\p{Cc}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    private const SLUG_WORDS_MAX = 100;

    public function __construct(
        private readonly Database $database,
        private readonly AuditLogService $audit,
        private readonly StoryService $stories,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    public function canManage(User $actor, Series $series): bool
    {
        return $actor->can(Capability::WriteStories) && ($series->ownerId === $actor->id || $actor->can(Capability::EditAnyStory));
    }

    /**
     * @param array<string, mixed> $input title, summary, work_status
     * @throws GroupingException
     */
    public function create(User $actor, array $input, string $ipAddress = ''): Series
    {
        if (!$actor->can(Capability::WriteStories)) {
            throw new GroupingException('This account may not write stories, so it cannot start a series.');
        }

        $values = $this->validated($input);
        $now = date('Y-m-d H:i:s');

        $id = (int) $this->database->transaction(function () use ($actor, $values, $now, $ipAddress): int {
            // The address is the id followed by the title words, so two series with one title never collide. The id is
            // only known after the insert, hence the throwaway first value inside one transaction.
            $id = (int) $this->database->insertGetId(
                'INSERT INTO {prefix}series (owner_id, title, slug, summary, work_status, created_at, updated_at)
                 VALUES (:owner, :title, :slug, :summary, :status, :created, :updated)',
                ['owner' => $actor->id, 'title' => $values['title'], 'slug' => 'new-' . bin2hex(random_bytes(8)), 'summary' => $values['summary'], 'status' => $values['work_status'], 'created' => $now, 'updated' => $now],
            );
            $this->database->execute('UPDATE {prefix}series SET slug = :slug WHERE id = :id', ['slug' => $id . '-' . Slug::fromText($values['title'], self::SLUG_WORDS_MAX, 'series'), 'id' => $id]);
            $this->audit->record($actor->id, 'series.create', 'series', (string) $id, ['title' => $values['title']], $ipAddress);

            return $id;
        });

        return $this->mustFind($id);
    }

    /**
     * @param array<string, mixed> $input
     * @throws GroupingException
     */
    public function update(User $actor, int $seriesId, array $input, string $ipAddress = ''): Series
    {
        $series = $this->manageable($actor, $seriesId);
        $values = $this->validated($input);

        $this->database->transaction(function () use ($actor, $series, $values, $ipAddress): void {
            $this->database->execute(
                'UPDATE {prefix}series SET title = :title, summary = :summary, work_status = :status, updated_at = :updated WHERE id = :id',
                ['title' => $values['title'], 'summary' => $values['summary'], 'status' => $values['work_status'], 'updated' => date('Y-m-d H:i:s'), 'id' => $series->id],
            );
            $this->audit->record($actor->id, 'series.update', 'series', (string) $series->id, ['title' => $values['title']], $ipAddress);
        });

        return $this->mustFind($series->id);
    }

    /**
     * Removes the series and its links. The stories are not touched.
     *
     * @throws GroupingException
     */
    public function delete(User $actor, int $seriesId, string $ipAddress = ''): void
    {
        $series = $this->manageable($actor, $seriesId);
        $storyIds = array_map(static fn (array $row): int => (int) $row['story_id'], $this->database->fetchAll('SELECT story_id FROM {prefix}series_stories WHERE series_id = :id', ['id' => $series->id]));

        $this->database->transaction(function () use ($actor, $series, $ipAddress): void {
            $this->database->execute('DELETE FROM {prefix}series_stories WHERE series_id = :id', ['id' => $series->id]);
            $this->database->execute('DELETE FROM {prefix}series WHERE id = :id', ['id' => $series->id]);
            $this->audit->record($actor->id, 'series.delete', 'series', (string) $series->id, ['title' => $series->title], $ipAddress);
        });

        foreach ($storyIds as $storyId) {
            $this->hooks?->doAction('story_removed_from_series', $storyId, $series->id);
        }
    }

    public function find(int $id): ?Series
    {
        $row = $this->database->fetchOne('SELECT * FROM {prefix}series WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findBySlug(string $slug): ?Series
    {
        $row = $this->database->fetchOne('SELECT * FROM {prefix}series WHERE slug = :slug', ['slug' => $slug]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * The series a person manages: their own, or every series for staff who may edit any story and ask for all.
     *
     * @return list<array{series: Series, stories: int, owner: string}>
     */
    public function listFor(User $actor, bool $all = false): array
    {
        if (!$actor->can(Capability::WriteStories)) {
            return [];
        }

        $everyone = $all && $actor->can(Capability::EditAnyStory);
        $rows = $this->database->fetchAll(
            'SELECT sr.*, u.display_name AS owner_name, (SELECT COUNT(*) FROM {prefix}series_stories ss WHERE ss.series_id = sr.id) AS story_count
             FROM {prefix}series sr LEFT JOIN {prefix}users u ON u.id = sr.owner_id'
            . ($everyone ? '' : ' WHERE sr.owner_id = :owner') . ' ORDER BY sr.updated_at DESC, sr.id DESC LIMIT 500',
            $everyone ? [] : ['owner' => $actor->id],
        );

        return array_map(fn (array $row): array => ['series' => $this->hydrate($row), 'stories' => (int) $row['story_count'], 'owner' => (string) ($row['owner_name'] ?? '')], $rows);
    }

    /**
     * @return list<Series> a person's own series, for the story form's choice
     */
    public function ownedBy(int $ownerId): array
    {
        return array_map($this->hydrate(...), $this->database->fetchAll('SELECT * FROM {prefix}series WHERE owner_id = :owner ORDER BY title ASC, id ASC LIMIT 500', ['owner' => $ownerId]));
    }

    /**
     * Every story in the series in order, whatever its state: the management screen needs to show a draft.
     *
     * @return list<Story>
     */
    public function members(int $seriesId): array
    {
        return $this->stories->fromRows($this->database->fetchAll(
            'SELECT s.* FROM {prefix}series_stories ss JOIN {prefix}stories s ON s.id = ss.story_id WHERE ss.series_id = :id ORDER BY ss.position ASC, s.id ASC',
            ['id' => $seriesId],
        ));
    }

    /**
     * The series a story is in, or null.
     */
    public function forStory(int $storyId): ?Series
    {
        $row = $this->database->fetchOne(
            'SELECT sr.* FROM {prefix}series sr JOIN {prefix}series_stories ss ON ss.series_id = sr.id WHERE ss.story_id = :story',
            ['story' => $storyId],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Which of these stories are already in a series.
     *
     * @param list<int> $storyIds
     * @return array<int, int> story id => series id
     */
    public function seriesOfStories(array $storyIds): array
    {
        if ($storyIds === []) {
            return [];
        }

        $map = [];

        foreach ($this->database->fetchAll('SELECT story_id, series_id FROM {prefix}series_stories WHERE story_id IN (' . implode(',', array_map('intval', $storyIds)) . ')') as $row) {
            $map[(int) $row['story_id']] = (int) $row['series_id'];
        }

        return $map;
    }

    /**
     * The series each of these stories is in and its place there, for an export.
     *
     * @param list<int> $storyIds
     * @return array<int, array{series: Series, position: int}> story id => membership
     */
    public function membershipFor(array $storyIds): array
    {
        if ($storyIds === []) {
            return [];
        }

        $map = [];

        foreach ($this->database->fetchAll(
            'SELECT sr.*, ss.story_id AS member_story, ss.position AS member_position
             FROM {prefix}series sr JOIN {prefix}series_stories ss ON ss.series_id = sr.id WHERE ss.story_id IN (' . implode(',', array_map('intval', $storyIds)) . ')',
        ) as $row) {
            $map[(int) $row['member_story']] = ['series' => $this->hydrate($row), 'position' => (int) $row['member_position']];
        }

        return $map;
    }

    /**
     * Where a story stands in its series, counting from 1, or null when it is in none.
     */
    public function positionOf(int $storyId): ?int
    {
        $position = $this->database->fetchColumn('SELECT position FROM {prefix}series_stories WHERE story_id = :story', ['story' => $storyId]);

        return $position === null || $position === false ? null : (int) $position;
    }

    /**
     * Adds a story to the end of a series, or at a position. A story that is already in a series is refused: moving it is
     * a separate, explicit step (assign()), so one click cannot quietly take a story out of another series.
     *
     * @throws GroupingException
     */
    public function addStory(User $actor, int $seriesId, int $storyId, ?int $position = null, string $ipAddress = ''): void
    {
        $series = $this->manageable($actor, $seriesId);
        $story = $this->manageableStory($actor, $storyId);

        $this->database->transaction(function () use ($actor, $series, $story, $position, $ipAddress): void {
            $this->link($series, $story, $position);
            $this->audit->record($actor->id, 'series.add_story', 'series', (string) $series->id, ['story' => $story->id], $ipAddress);
        });

        $this->hooks?->doAction('story_added_to_series', $story->id, $series->id);
    }

    /**
     * @throws GroupingException
     */
    public function removeStory(User $actor, int $seriesId, int $storyId, string $ipAddress = ''): void
    {
        $series = $this->manageable($actor, $seriesId);

        if (!$this->contains($series->id, $storyId)) {
            throw new GroupingException('That story is not in this series.');
        }

        $this->database->transaction(function () use ($actor, $series, $storyId, $ipAddress): void {
            $this->unlink($series->id, $storyId);
            $this->audit->record($actor->id, 'series.remove_story', 'series', (string) $series->id, ['story' => $storyId], $ipAddress);
        });

        $this->hooks?->doAction('story_removed_from_series', $storyId, $series->id);
    }

    /**
     * Moves a story one place earlier or later. At either end nothing changes.
     *
     * @param string $direction up or down
     * @throws GroupingException
     */
    public function move(User $actor, int $seriesId, int $storyId, string $direction, string $ipAddress = ''): void
    {
        $series = $this->manageable($actor, $seriesId);
        $order = $this->order($series->id);
        $index = array_search($storyId, $order, true);

        if ($index === false) {
            throw new GroupingException('That story is not in this series.');
        }

        $swap = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : -1);

        if ($swap < 0 || $swap >= count($order)) {
            return;
        }

        [$order[$index], $order[$swap]] = [$order[$swap], $order[$index]];

        $this->database->transaction(function () use ($actor, $series, $order, $storyId, $direction, $ipAddress): void {
            $this->renumber($series->id, $order);
            $this->touch($series->id);
            $this->audit->record($actor->id, 'series.move_story', 'series', (string) $series->id, ['story' => $storyId, 'direction' => $direction], $ipAddress);
        });
    }

    /**
     * Puts a story in a series, takes it out of the one it was in, or only changes its place: what the story form asks for.
     * A null series means "none".
     *
     * @throws GroupingException
     */
    public function assign(User $actor, int $storyId, ?int $seriesId, ?int $position = null, string $ipAddress = ''): void
    {
        $story = $this->manageableStory($actor, $storyId);
        $current = $this->forStory($story->id);

        if ($seriesId === null) {
            if ($current !== null) {
                $this->removeStory($actor, $current->id, $story->id, $ipAddress);
            }

            return;
        }

        $target = $this->manageable($actor, $seriesId);

        if ($current !== null && $current->id === $target->id) {
            if ($position !== null) {
                $this->place($actor, $target, $story->id, $position, $ipAddress);
            }

            return;
        }

        $this->database->transaction(function () use ($actor, $current, $target, $story, $position, $ipAddress): void {
            if ($current !== null) {
                $this->unlink($current->id, $story->id);
            }

            $this->link($target, $story, $position);
            $this->audit->record($actor->id, 'series.add_story', 'series', (string) $target->id, ['story' => $story->id, 'from' => $current?->id], $ipAddress);
        });

        if ($current !== null) {
            $this->hooks?->doAction('story_removed_from_series', $story->id, $current->id);
        }

        $this->hooks?->doAction('story_added_to_series', $story->id, $target->id);
    }

    // ---- what the public sees ------------------------------------------------------------------

    /**
     * The stories of a series a visitor may open, in order. Anything else is left out.
     *
     * @return list<Story>
     */
    public function publicStories(Series $series): array
    {
        return $this->stories->fromRows($this->database->fetchAll(
            'SELECT s.* FROM {prefix}series_stories ss JOIN {prefix}stories s ON s.id = ss.story_id
             WHERE ss.series_id = :id AND ' . StoryVisibility::publicCondition('s') . ' ORDER BY ss.position ASC, s.id ASC',
            ['id' => $series->id],
        ));
    }

    /**
     * A series by its address, only while at least one of its stories is public. One whose stories are all drafts answers
     * like a missing page, so a title cannot be found by guessing.
     */
    public function findPublicBySlug(string $slug): ?Series
    {
        $series = $this->findBySlug($slug);

        return $series !== null && $this->publicCount($series->id) > 0 ? $series : null;
    }

    public function publicCount(int $seriesId): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM {prefix}series_stories ss JOIN {prefix}stories s ON s.id = ss.story_id WHERE ss.series_id = :id AND ' . StoryVisibility::publicCondition('s'),
            ['id' => $seriesId],
        );
    }

    /**
     * Totals over the stories a visitor can open, never over the whole series.
     *
     * @param list<Story> $publicStories
     * @return array{stories: int, words: int, chapters: int}
     */
    public function totals(array $publicStories): array
    {
        $ids = array_map(static fn (Story $story): int => $story->id, $publicStories);
        $chapters = 0;

        if ($ids !== []) {
            $chapters = (int) $this->database->fetchColumn(
                'SELECT COUNT(*) FROM {prefix}chapters WHERE publication_state = \'published\' AND story_id IN (' . implode(',', array_map('intval', $ids)) . ')',
            );
        }

        return [
            'stories' => count($publicStories),
            'words' => array_sum(array_map(static fn (Story $story): int => $story->wordCount, $publicStories)),
            'chapters' => $chapters,
        ];
    }

    /**
     * The "Part 2 of 3" facts for a story page, over public stories only, or null when the story is in no series (or is not
     * itself public).
     *
     * @return array{series: Series, position: int, total: int, previous: ?Story, next: ?Story}|null
     */
    public function partOf(Story $story): ?array
    {
        $series = $this->forStory($story->id);

        if ($series === null) {
            return null;
        }

        $public = $this->publicStories($series);

        foreach ($public as $index => $candidate) {
            if ($candidate->id === $story->id) {
                return [
                    'series' => $series,
                    'position' => $index + 1,
                    'total' => count($public),
                    'previous' => $public[$index - 1] ?? null,
                    'next' => $public[$index + 1] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Series that have a story a visitor can open, for the sitemap.
     *
     * @return list<Series>
     */
    public function listPublic(int $limit, int $offset = 0): array
    {
        return array_map($this->hydrate(...), $this->database->fetchAll(
            'SELECT sr.* FROM {prefix}series sr WHERE ' . self::hasPublicStory('sr') . ' ORDER BY sr.id ASC LIMIT ' . max(1, min(50000, $limit)) . ' OFFSET ' . max(0, $offset),
        ));
    }

    public function countPublic(): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}series sr WHERE ' . self::hasPublicStory('sr'));
    }

    /**
     * SQL: the series has at least one story a visitor can open. Holds no user data.
     */
    private static function hasPublicStory(string $alias): string
    {
        return "EXISTS (SELECT 1 FROM {prefix}series_stories pss JOIN {prefix}stories s ON s.id = pss.story_id WHERE pss.series_id = {$alias}.id AND " . StoryVisibility::publicCondition('s') . ')';
    }

    // ---- internals -----------------------------------------------------------------------------

    /**
     * @return array{title: string, summary: string, work_status: string}
     * @throws GroupingException
     */
    private function validated(array $input): array
    {
        $errors = [];
        $title = trim((string) preg_replace('/\s+/u', ' ', is_string($input['title'] ?? null) ? $input['title'] : ''));
        $summary = trim(str_replace(["\r\n", "\r"], "\n", is_string($input['summary'] ?? null) ? $input['summary'] : ''));
        $status = WorkStatus::tryFrom(is_string($input['work_status'] ?? null) ? $input['work_status'] : WorkStatus::Ongoing->value);

        if ($title === '') {
            $errors['title'] = 'Give the series a title.';
        } elseif (mb_strlen($title, 'UTF-8') > self::TITLE_MAX) {
            $errors['title'] = 'The title can be at most ' . self::TITLE_MAX . ' characters.';
        } elseif (preg_match(self::FORBIDDEN, $title) === 1) {
            $errors['title'] = 'The title cannot hold hidden or control characters.';
        }

        if (mb_strlen($summary, 'UTF-8') > self::SUMMARY_MAX) {
            $errors['summary'] = 'The summary can be at most ' . self::SUMMARY_MAX . ' characters.';
        } elseif (preg_match('/[^\P{Cc}\n\t]/u', $summary) === 1) {
            $errors['summary'] = 'The summary cannot hold control characters.';
        }

        if ($status === null) {
            $errors['work_status'] = 'Choose whether the series is ongoing or finished.';
        }

        if ($errors !== []) {
            throw new GroupingException('The series details are not valid.', $errors);
        }

        return ['title' => $title, 'summary' => $summary, 'work_status' => ($status ?? WorkStatus::Ongoing)->value];
    }

    /**
     * @throws GroupingException
     */
    private function manageable(User $actor, int $seriesId): Series
    {
        $series = $this->find($seriesId);

        if ($series === null || !$this->canManage($actor, $series)) {
            throw new GroupingException('That series is not available.');
        }

        return $series;
    }

    /**
     * @throws GroupingException when the story is missing, in the trash or not the actor's to change
     */
    private function manageableStory(User $actor, int $storyId): Story
    {
        try {
            return $this->stories->manageable($actor, $storyId);
        } catch (StoryPermissionException) {
            throw new GroupingException('That story is not available.');
        }
    }

    private function mustFind(int $id): Series
    {
        return $this->find($id) ?? throw new GroupingException('That series is not available.');
    }

    private function contains(int $seriesId, int $storyId): bool
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}series_stories WHERE series_id = :series AND story_id = :story', ['series' => $seriesId, 'story' => $storyId]) === 1;
    }

    /**
     * @return list<int> the story ids of a series in order
     */
    private function order(int $seriesId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['story_id'],
            $this->database->fetchAll('SELECT story_id FROM {prefix}series_stories WHERE series_id = :id ORDER BY position ASC, story_id ASC', ['id' => $seriesId]),
        );
    }

    /**
     * Inserts the link. Runs inside the caller's transaction.
     *
     * @throws GroupingException
     */
    private function link(Series $series, Story $story, ?int $position): void
    {
        if ($story->ownerId !== $series->ownerId) {
            throw new GroupingException('A series can only hold stories by its author.');
        }

        if ($this->forStory($story->id) !== null) {
            throw new GroupingException('That story is already in a series. Take it out of that one first.');
        }

        $order = $this->order($series->id);
        $index = $position === null ? count($order) : max(0, min(count($order), $position - 1));
        array_splice($order, $index, 0, [$story->id]);

        $this->database->execute('INSERT INTO {prefix}series_stories (series_id, story_id, position) VALUES (:series, :story, 0)', ['series' => $series->id, 'story' => $story->id]);
        $this->renumber($series->id, $order);
        $this->touch($series->id);
    }

    /**
     * Deletes one link and closes the gap. Runs inside the caller's transaction.
     */
    private function unlink(int $seriesId, int $storyId): void
    {
        $this->database->execute('DELETE FROM {prefix}series_stories WHERE series_id = :series AND story_id = :story', ['series' => $seriesId, 'story' => $storyId]);
        $this->renumber($seriesId, $this->order($seriesId));
        $this->touch($seriesId);
    }

    private function place(User $actor, Series $series, int $storyId, int $position, string $ipAddress): void
    {
        $before = $this->order($series->id);
        $order = array_values(array_filter($before, static fn (int $id): bool => $id !== $storyId));
        array_splice($order, max(0, min(count($order), $position - 1)), 0, [$storyId]);

        // The story form sends the number it showed, so saving without touching it must not count as a change.
        if ($order === $before) {
            return;
        }

        $this->database->transaction(function () use ($actor, $series, $order, $storyId, $ipAddress): void {
            $this->renumber($series->id, $order);
            $this->touch($series->id);
            $this->audit->record($actor->id, 'series.move_story', 'series', (string) $series->id, ['story' => $storyId], $ipAddress);
        });
    }

    /**
     * @param list<int> $order story ids in the wanted order
     */
    private function renumber(int $seriesId, array $order): void
    {
        foreach ($order as $index => $storyId) {
            $this->database->execute(
                'UPDATE {prefix}series_stories SET position = :position WHERE series_id = :series AND story_id = :story',
                ['position' => $index + 1, 'series' => $seriesId, 'story' => $storyId],
            );
        }
    }

    private function touch(int $seriesId): void
    {
        $this->database->execute('UPDATE {prefix}series SET updated_at = :updated WHERE id = :id', ['updated' => date('Y-m-d H:i:s'), 'id' => $seriesId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Series
    {
        return new Series(
            (int) $row['id'],
            (int) $row['owner_id'],
            (string) $row['title'],
            (string) $row['slug'],
            (string) $row['summary'],
            WorkStatus::tryFrom((string) $row['work_status']) ?? WorkStatus::Ongoing,
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
