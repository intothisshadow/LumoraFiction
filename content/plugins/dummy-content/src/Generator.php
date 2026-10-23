<?php

/**
 * Makes placeholder users, terms, stories, chapters and bookmarks for testing.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\DummyContent;

use LumoraFiction\Core\Database\Database;
use LumoraFiction\Helpers\Slug;
use LumoraFiction\Helpers\WordCounter;
use LumoraFiction\Models\ContentFormat;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuditLogService;
use LumoraFiction\Services\TaxonomyService;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Rows are written with multi-row inserts instead of through the story and chapter services: those validate and
 * log every call, which is right for a person but would take hours for tens of thousands of stories. The columns
 * and the invariants the services keep (story word counts, term story counts, unique slugs) are kept by hand here,
 * so a change to those tables means a change to this file too.
 */
final class Generator
{
    /** Values of the config.php "environment" setting under which generating is allowed. */
    private const SAFE_ENVIRONMENTS = ['development', 'testing'];

    private const STORY_BATCH = 100;

    private const PARAGRAPH_POOL = 300;

    /** @var array<string, list<array{html: string, words: int}>> */
    private array $pool = [];

    public function __construct(
        private readonly Database $database,
        private readonly TaxonomyService $taxonomy,
        private readonly AuditLogService $audit,
        private readonly Tracker $tracker,
        private readonly string $environment,
    ) {
    }

    /**
     * Anything but an explicit development or testing flag counts as a live site, so a config.php that was
     * never edited is safe.
     */
    public static function allowedIn(string $environment): bool
    {
        return in_array(strtolower(trim($environment)), self::SAFE_ENVIRONMENTS, true);
    }

    public function allowed(): bool
    {
        return self::allowedIn($this->environment);
    }

    /**
     * @return array{run: string, users: int, terms: int, stories: int, chapters: int, words: int, bookmarks: int, seconds: float}
     */
    public function generate(Options $options, User $actor, string $ipAddress = ''): array
    {
        $this->assertAllowed();

        // A big run is minutes of work on a modest machine; the web server's usual limit would cut it short.
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $started = microtime(true);
        $this->tracker->ensureTable();

        $run = bin2hex(random_bytes(3));
        $corpus = new Corpus($options->seed === null ? new Randomizer() : new Randomizer(new Mt19937($options->seed)));
        $this->buildPool($corpus, $run);

        $report = ['run' => $run, 'users' => 0, 'terms' => 0, 'stories' => 0, 'chapters' => 0, 'words' => 0, 'bookmarks' => 0, 'seconds' => 0.0];

        $report['users'] = $this->makeUsers($options->users, $run, $corpus);
        $report['terms'] = $this->makeTerms($options, $run, $corpus, $actor);

        $authors = $this->authorIds();
        $pools = $this->loadPools();

        for ($offset = 0; $offset < $options->stories; $offset += self::STORY_BATCH) {
            $made = $this->makeStories(min(self::STORY_BATCH, $options->stories - $offset), $offset, $run, $options, $corpus, $authors === [] ? [$actor->id] : $authors, $pools);
            $report['stories'] += $made['stories'];
            $report['chapters'] += $made['chapters'];
            $report['words'] += $made['words'];
        }

        $report['bookmarks'] = $this->makeBookmarks($options->bookmarks, $corpus);

        // The term pages show these counts; the inserts above do not keep them.
        $this->taxonomy->refreshAllCounts();

        $report['seconds'] = round(microtime(true) - $started, 2);
        $this->audit->record($actor->id, 'dummy_content.generated', 'plugin', 'dummy-content', [
            'run' => $run,
            'users' => $report['users'],
            'stories' => $report['stories'],
            'chapters' => $report['chapters'],
        ], $ipAddress);

        return $report;
    }

    /**
     * @return array{user: int, story: int, term: int, chapter: int, bookmark: int}
     */
    public function removeAll(User $actor, string $ipAddress = ''): array
    {
        $this->assertAllowed();

        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $this->tracker->ensureTable();
        $removed = $this->tracker->removeAll();

        // Terms that were not removed lose the stories that used them.
        $this->taxonomy->refreshAllCounts();
        $this->audit->record($actor->id, 'dummy_content.removed', 'plugin', 'dummy-content', $removed, $ipAddress);

        return $removed;
    }

    private function assertAllowed(): void
    {
        if (!$this->allowed()) {
            throw new RuntimeException('Dummy content can only be made on a site whose config.php sets "environment" to "development" or "testing".');
        }
    }

    private function buildPool(Corpus $corpus, string $run): void
    {
        $this->pool[$run] = [];

        for ($i = 0; $i < self::PARAGRAPH_POOL; $i++) {
            $html = '<p>' . $corpus->paragraph() . '</p>';
            $this->pool[$run][] = ['html' => $html, 'words' => WordCounter::count($html, ContentFormat::Html)];
        }
    }

    private function makeUsers(int $count, string $run, Corpus $corpus): int
    {
        if ($count === 0) {
            return 0;
        }

        // Nobody knows this password, so a generated account can be listed and viewed but never signed in to.
        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $before = $this->maxId('users');
        $rows = [];

        for ($n = 1; $n <= $count; $n++) {
            $name = $corpus->personName();
            $joined = $this->timeAgo($corpus, 1095);
            $rows[] = [
                'dummy-' . $run . '-' . $n,
                $name,
                Slug::fromText($name, 60, 'writer') . '-' . $run . '-' . $n,
                'dummy-' . $run . '-' . $n . '@example.invalid',
                $hash,
                $corpus->chance(80) ? 'author' : 'reader',
                'active',
                $joined,
                $joined,
                $joined,
            ];
        }

        $this->insertRows('users', ['login_name', 'display_name', 'slug', 'email', 'password_hash', 'role', 'status', 'email_verified_at', 'created_at', 'updated_at'], $rows);

        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->fetchAll('SELECT id FROM {prefix}users WHERE id > :before AND login_name LIKE :prefix', ['before' => $before, 'prefix' => 'dummy-' . $run . '-%']),
        );
        $this->tracker->record(Tracker::KIND_USER, $ids, $run);

        return count($ids);
    }

    /**
     * Fandoms and tags in the number asked for, plus a few characters and relationships inside each fandom.
     */
    private function makeTerms(Options $options, string $run, Corpus $corpus, User $actor): int
    {
        $total = 0;

        $fandoms = $this->createTerms(TermType::Fandom, [0], $options->fandoms, $corpus->fandomName(...), $run, $actor);
        $fandomIds = array_column($fandoms, 'id');
        $total += count($fandoms);

        if ($fandomIds !== []) {
            $total += count($this->createTerms(TermType::Character, $fandomIds, 4, $corpus->personName(...), $run, $actor));
            $total += count($this->createTerms(TermType::Relationship, $fandomIds, 2, static fn (): string => $corpus->firstName() . '/' . $corpus->firstName(), $run, $actor));
        }

        $total += count($this->createTerms(TermType::Tag, [0], $options->tags, $corpus->tagName(...), $run, $actor));

        return $total;
    }

    /**
     * @param list<int> $parents
     * @param callable(): string $namer
     * @return list<array{id: int, name: string, parent: int}>
     */
    private function createTerms(TermType $type, array $parents, int $perParent, callable $namer, string $run, User $actor): array
    {
        if ($perParent === 0) {
            return [];
        }

        $keys = [];
        $slugs = [];

        foreach ($this->database->fetchAll('SELECT parent_id, name_key, slug FROM {prefix}terms WHERE type = :type', ['type' => $type->value]) as $row) {
            $keys[$row['parent_id'] . '|' . $row['name_key']] = true;
            $slugs[(string) $row['slug']] = true;
        }

        $before = $this->maxId('terms');
        $now = date('Y-m-d H:i:s');
        $rows = [];
        $made = [];

        foreach ($parents as $parent) {
            $found = 0;

            // The name lists are short, so a clash is common; trying again beats giving fewer than asked for.
            for ($attempt = 0; $found < $perParent && $attempt < $perParent * 30; $attempt++) {
                $name = mb_substr(trim($namer()), 0, TaxonomyService::NAME_MAX);
                $key = mb_strtolower($name, 'UTF-8');

                if ($name === '' || isset($keys[$parent . '|' . $key])) {
                    continue;
                }

                $base = Slug::fromText($name, 100, 'term');
                $slug = $base;

                for ($suffix = 2; isset($slugs[$slug]); $suffix++) {
                    $slug = $base . '-' . $suffix;
                }

                $keys[$parent . '|' . $key] = true;
                $slugs[$slug] = true;
                $rows[] = [$type->value, $parent, $name, $key, $slug, 1, 0, $actor->id, $now];
                $made[$slug] = ['name' => $name, 'parent' => $parent];
                $found++;
            }
        }

        $this->insertRows('terms', ['type', 'parent_id', 'name', 'name_key', 'slug', 'reviewed', 'story_count', 'created_by', 'created_at'], $rows);

        $created = [];

        foreach ($this->database->fetchAll('SELECT id, slug FROM {prefix}terms WHERE id > :before AND type = :type', ['before' => $before, 'type' => $type->value]) as $row) {
            if (isset($made[(string) $row['slug']])) {
                $created[] = ['id' => (int) $row['id']] + $made[(string) $row['slug']];
            }
        }

        $this->tracker->record(Tracker::KIND_TERM, array_column($created, 'id'), $run);

        return $created;
    }

    /**
     * @return list<int>
     */
    private function authorIds(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->fetchAll("SELECT u.id FROM {prefix}users u JOIN {prefix}dummy_content_items t ON t.kind = 'user' AND t.item_id = u.id WHERE u.role = 'author' ORDER BY u.id"),
        );
    }

    /**
     * Every term a story may be given, so a run reuses what the site already has and what earlier runs made.
     *
     * @return array{fandom: list<int>, tag: list<int>, rating: list<int>, genre: list<int>, category: list<int>, warning: list<int>, relationship_category: list<int>, character: array<int, list<int>>, relationship: array<int, list<int>>}
     */
    private function loadPools(): array
    {
        $pools = ['fandom' => [], 'tag' => [], 'rating' => [], 'genre' => [], 'category' => [], 'warning' => [], 'relationship_category' => [], 'character' => [], 'relationship' => []];

        foreach ($this->database->fetchAll('SELECT id, type, parent_id FROM {prefix}terms WHERE reviewed = 1 ORDER BY id') as $row) {
            $type = (string) $row['type'];

            if (!array_key_exists($type, $pools)) {
                continue;
            }

            if ($type === 'character' || $type === 'relationship') {
                $pools[$type][(int) $row['parent_id']][] = (int) $row['id'];
            } else {
                $pools[$type][] = (int) $row['id'];
            }
        }

        return $pools;
    }

    /**
     * @param list<int> $authors
     * @param array<string, mixed> $pools
     * @return array{stories: int, chapters: int, words: int}
     */
    private function makeStories(int $count, int $offset, string $run, Options $options, Corpus $corpus, array $authors, array $pools): array
    {
        $now = time();
        $plans = [];
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $n = $offset + $i;
            $title = $corpus->storyTitle();
            $slug = 'dummy-' . $run . '-' . $n . '-' . Slug::fromText($title, 60, 'story');
            $state = $this->publicationState($corpus);
            $chapterCount = $this->chapterCount($corpus, $options->maxChapters);
            $chapters = $this->makeChapters($chapterCount, $state, $options->words, $corpus, $run, $now);

            $first = $chapters[0]['created'];
            $last = $chapters[$chapterCount - 1]['created'];
            $words = array_sum(array_column($chapters, 'words'));

            $rows[] = [
                $corpus->pick($authors),
                $title,
                $slug,
                $corpus->summary(),
                $corpus->chance(20) ? $corpus->sentence() : '',
                $corpus->chance(15) ? 'None of this belongs to me.' : '',
                'en',
                $this->workStatus($corpus, $chapterCount),
                $state,
                $words,
                $state === 'published' || $state === 'unpublished' ? $first : null,
                $first,
                $last,
            ];
            $plans[$slug] = ['chapters' => $chapters, 'words' => $words];
        }

        $before = $this->maxId('stories');
        $this->insertRows('stories', ['owner_id', 'title', 'slug', 'summary', 'notes', 'disclaimer', 'language', 'work_status', 'publication_state', 'word_count', 'published_at', 'created_at', 'updated_at'], $rows);

        $ids = [];

        foreach ($this->database->fetchAll('SELECT id, slug FROM {prefix}stories WHERE id > :before AND slug LIKE :prefix', ['before' => $before, 'prefix' => 'dummy-' . $run . '-%']) as $row) {
            if (isset($plans[(string) $row['slug']])) {
                $ids[(string) $row['slug']] = (int) $row['id'];
            }
        }

        $this->tracker->record(Tracker::KIND_STORY, array_values($ids), $run);

        $chapterRows = [];
        $linkRows = [];
        $words = 0;

        foreach ($ids as $slug => $storyId) {
            foreach ($plans[$slug]['chapters'] as $number => $chapter) {
                $chapterRows[] = [$storyId, $number + 1, $chapter['title'], $chapter['content'], 'html', '', $chapter['words'], $chapter['state'], $chapter['published'], $chapter['created'], $chapter['created']];
            }

            $words += $plans[$slug]['words'];

            foreach ($this->termsForStory($corpus, $pools) as $termId) {
                $linkRows[] = [$storyId, $termId];
            }
        }

        // Chapters carry the text, so they go in smaller groups to stay under the server's packet limit.
        $this->insertRows('chapters', ['story_id', 'number', 'title', 'content', 'content_format', 'notes', 'word_count', 'publication_state', 'published_at', 'created_at', 'updated_at'], $chapterRows, 40);
        $this->insertRows('story_terms', ['story_id', 'term_id'], $linkRows, null, true);

        return ['stories' => count($ids), 'chapters' => count($chapterRows), 'words' => $words];
    }

    /**
     * @return list<array{title: string, content: string, words: int, state: string, published: string|null, created: string}>
     */
    private function makeChapters(int $count, string $storyState, int $averageWords, Corpus $corpus, string $run, int $now): array
    {
        $chapters = [];
        $time = $now - $corpus->between(86400, 1095 * 86400);

        for ($number = 1; $number <= $count; $number++) {
            $target = $corpus->between(intdiv($averageWords, 2), $averageWords + intdiv($averageWords, 2));
            $content = [];
            $words = 0;

            while ($words < $target) {
                $paragraph = $corpus->pick($this->pool[$run]);
                $content[] = $paragraph['html'];
                $words += $paragraph['words'];
            }

            // A published story keeps a draft last chapter now and then, to show a reader who is not meant to see it.
            $state = match ($storyState) {
                'published' => $number === $count && $count >= 3 && $corpus->chance(10) ? 'draft' : 'published',
                'unpublished' => 'published',
                default => 'draft',
            };
            $stamp = date('Y-m-d H:i:s', min($time, $now));

            $chapters[] = [
                'title' => $corpus->chapterTitle(),
                'content' => implode("\n", $content),
                'words' => $words,
                'state' => $state,
                'published' => $state === 'published' ? $stamp : null,
                'created' => $stamp,
            ];

            $time += $corpus->between(86400, 30 * 86400);
        }

        return $chapters;
    }

    /**
     * @param array<string, mixed> $pools
     * @return list<int>
     */
    private function termsForStory(Corpus $corpus, array $pools): array
    {
        $ids = [];

        if ($pools['fandom'] !== []) {
            $fandoms = [$corpus->pickPopular($pools['fandom'])];

            if ($corpus->chance(10)) {
                $fandoms[] = $corpus->pickPopular($pools['fandom']);
            }

            foreach (array_unique($fandoms) as $fandom) {
                $ids[] = $fandom;
                $ids = [...$ids, ...$this->some($corpus, $pools['character'][$fandom] ?? [], $corpus->between(1, 3))];

                if ($corpus->chance(40)) {
                    $ids = [...$ids, ...$this->some($corpus, $pools['relationship'][$fandom] ?? [], 1)];
                }
            }
        }

        $ids = [...$ids, ...$this->some($corpus, $pools['tag'], $corpus->between(2, 6), true)];
        $ids = [...$ids, ...$this->some($corpus, $pools['rating'], 1)];
        $ids = [...$ids, ...$this->some($corpus, $pools['genre'], $corpus->between(1, 2))];

        if ($corpus->chance(50)) {
            $ids = [...$ids, ...$this->some($corpus, $pools['warning'], $corpus->between(1, 2))];
        }

        if ($corpus->chance(60)) {
            $ids = [...$ids, ...$this->some($corpus, $pools['category'], 1)];
        }

        if ($corpus->chance(50)) {
            $ids = [...$ids, ...$this->some($corpus, $pools['relationship_category'], 1)];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $pool
     * @return list<int>
     */
    private function some(Corpus $corpus, array $pool, int $wanted, bool $popular = false): array
    {
        if ($pool === []) {
            return [];
        }

        $chosen = [];

        for ($attempt = 0; count($chosen) < min($wanted, count($pool)) && $attempt < $wanted * 6; $attempt++) {
            $chosen[] = $popular ? $corpus->pickPopular($pool) : $corpus->pick($pool);
            $chosen = array_values(array_unique($chosen));
        }

        return $chosen;
    }

    private function makeBookmarks(int $wanted, Corpus $corpus): int
    {
        if ($wanted === 0) {
            return 0;
        }

        $users = $this->tracker->ids(Tracker::KIND_USER);
        $stories = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->fetchAll("SELECT s.id FROM {prefix}stories s JOIN {prefix}dummy_content_items t ON t.kind = 'story' AND t.item_id = s.id WHERE s.publication_state = 'published'"),
        );

        if ($users === [] || $stories === []) {
            return 0;
        }

        $pairs = [];

        for ($attempt = 0; count($pairs) < $wanted && $attempt < $wanted * 3; $attempt++) {
            $user = $corpus->pick($users);
            $story = $corpus->pickPopular($stories);
            $pairs[$user . ':' . $story] = [$user, $story, 0, $this->timeAgo($corpus, 365)];
        }

        return $this->insertRows('bookmarks', ['user_id', 'story_id', 'chapter_id', 'created_at'], array_values($pairs), null, true);
    }

    private function publicationState(Corpus $corpus): string
    {
        $roll = $corpus->between(1, 100);

        return match (true) {
            $roll <= 85 => 'published',
            $roll <= 90 => 'draft',
            $roll <= 95 => 'pending',
            default => 'unpublished',
        };
    }

    private function chapterCount(Corpus $corpus, int $most): int
    {
        if ($most === 1 || $corpus->chance(30)) {
            return 1;
        }

        // Squaring a fraction makes short serials common and the longest rare.
        $fraction = $corpus->between(0, 1000) / 1000;

        return min($most, 2 + (int) floor($fraction * $fraction * ($most - 1)));
    }

    private function workStatus(Corpus $corpus, int $chapterCount): string
    {
        $roll = $corpus->between(1, 100);

        if ($chapterCount === 1) {
            return $roll <= 90 ? 'completed' : 'ongoing';
        }

        return match (true) {
            $roll <= 45 => 'ongoing',
            $roll <= 80 => 'completed',
            $roll <= 92 => 'on_hiatus',
            default => 'abandoned',
        };
    }

    private function timeAgo(Corpus $corpus, int $maxDays): string
    {
        return date('Y-m-d H:i:s', time() - $corpus->between(0, $maxDays * 86400));
    }

    private function maxId(string $table): int
    {
        return (int) $this->database->fetchColumn('SELECT COALESCE(MAX(id), 0) FROM {prefix}' . $table);
    }

    /**
     * Table and column names come from this file, never from a request; the values are bound.
     *
     * @param list<string> $columns
     * @param list<list<mixed>> $rows
     * @return int rows written
     */
    private function insertRows(string $table, array $columns, array $rows, ?int $perStatement = null, bool $ignore = false): int
    {
        if ($rows === []) {
            return 0;
        }

        $perStatement ??= max(1, intdiv(30000, count($columns)));
        $written = 0;
        $group = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        foreach (array_chunk($rows, $perStatement) as $chunk) {
            $written += $this->database->execute(
                'INSERT ' . ($ignore ? 'IGNORE ' : '') . 'INTO {prefix}' . $table . ' (' . implode(', ', $columns) . ') VALUES ' . implode(', ', array_fill(0, count($chunk), $group)),
                array_merge(...$chunk),
            );
        }

        return $written;
    }
}
