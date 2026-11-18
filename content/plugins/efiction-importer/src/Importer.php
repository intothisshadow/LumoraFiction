<?php

/**
 * Brings an eFiction archive across in small resumable steps, and removes what it brought.
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

namespace LumoraFiction\Plugins\EfictionImporter;

use Closure;
use InvalidArgumentException;
use LumoraFiction\Core\Content\HtmlSanitizer;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Helpers\Slug;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\User;
use LumoraFiction\Models\UserRole;
use LumoraFiction\Models\UserStatus;
use LumoraFiction\Models\WorkStatus;
use LumoraFiction\Services\AccountRules;
use LumoraFiction\Services\AuditLogService;
use LumoraFiction\Services\ChapterService;
use LumoraFiction\Services\CommentException;
use LumoraFiction\Services\CommentService;
use LumoraFiction\Services\DuplicateAccountException;
use LumoraFiction\Services\GroupingException;
use LumoraFiction\Services\SeriesService;
use LumoraFiction\Services\NewsService;
use LumoraFiction\Services\PageService;
use LumoraFiction\Services\StoryService;
use LumoraFiction\Services\TaxonomyService;
use LumoraFiction\Services\UserService;
use Throwable;

/**
 * Every write goes through the same services a person's own work does, so what is imported obeys the same rules, sanitiser
 * and visibility. The exceptions are named where they happen: a password hash that eFiction already stored properly, the
 * date a post was first written, and the comment and bookmark rows, whose services refuse to take old dates or a bulk of them.
 */
final class Importer
{
    public const LOCK_SECONDS = 60;

    /** The order the parts are brought in: what a later part points at is always made before it. */
    private const PHASES = ['ratings', 'categories', 'characters', 'classes', 'authors', 'stories', 'series', 'reviews', 'favorites', 'news', 'pages'];

    /** Rows read at a time, per part: a story carries all its chapters, so it goes a few at a time. */
    private const BATCH = ['authors' => 25, 'stories' => 5, 'news' => 20, 'pages' => 20];

    public function __construct(
        private readonly Database $database,
        private readonly Tracker $tracker,
        private readonly UserService $users,
        private readonly StoryService $stories,
        private readonly ChapterService $chapters,
        private readonly TaxonomyService $taxonomy,
        private readonly NewsService $news,
        private readonly PageService $pages,
        private readonly HtmlSanitizer $sanitizer,
        private readonly AuditLogService $audit,
        private readonly ?HookManager $hooks = null,
        private readonly float $stepSeconds = 8.0,
        private readonly ?CommentService $comments = null,
        private readonly ?SeriesService $series = null,
        private readonly ?Closure $seriesOn = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options reviews, favorites, news (bool), pages (list of message ids), bio (bool), unvalidated ("draft" or "approval"), language
     * @param array<string, mixed> $mapping the checked result of Mapping::fromInput()
     */
    public function begin(User $admin, array $options, array $mapping, string $ipAddress): int
    {
        $this->tracker->ensureTables();

        $seriesAvailable = $this->series !== null && ($this->seriesOn === null || ($this->seriesOn)());
        $notes = (bool) ($options['series'] ?? false) && !$seriesAvailable ? ['Series are switched off under Settings > Series and collections, so none were imported.'] : [];

        $phases = array_values(array_filter(self::PHASES, static fn (string $phase): bool => match ($phase) {
            'series' => (bool) ($options['series'] ?? false) && $seriesAvailable,
            'reviews' => (bool) ($options['reviews'] ?? false) || (bool) ($options['guest_reviews'] ?? false),
            'favorites' => (bool) ($options['favorites'] ?? false),
            'news' => (bool) ($options['news'] ?? false),
            'pages' => (($options['pages'] ?? []) !== []),
            'characters' => ($mapping['characters'] ?? 'import') !== 'skip',
            default => true,
        }));

        $job = ['options' => $options, 'mapping' => $mapping, 'phases' => $phases, 'phase' => $phases[0] ?? '', 'cursor' => 0, 'admin' => $admin->id, 'ip' => $ipAddress];
        $runId = $this->tracker->startRun($job, (new Report(notes: $notes))->toArray());
        $this->audit->record($admin->id, 'plugin.efiction_importer.start', 'plugin', 'efiction-importer', ['run' => $runId, 'parts' => $phases], $ipAddress);

        return $runId;
    }

    /**
     * Works on the run until the time is used, saving after each batch, so a run cut off by the host loses at most one batch.
     *
     * @return array{state: string, phase: string, done: bool, busy: bool, report: Report}
     */
    public function step(User $admin, Source $source, ChapterText $text, int $runId, ?float $seconds = null): array
    {
        $run = $this->tracker->run($runId);

        if ($run === null) {
            throw new InvalidArgumentException('That run does not exist.');
        }

        $report = Report::fromArray($run['report']);

        if ($run['state'] !== 'running') {
            return ['state' => $run['state'], 'phase' => '', 'done' => true, 'busy' => false, 'report' => $report];
        }

        if (!$this->tracker->lock($runId, self::LOCK_SECONDS)) {
            return ['state' => 'running', 'phase' => (string) ($run['job']['phase'] ?? ''), 'done' => false, 'busy' => true, 'report' => $report];
        }

        $job = $run['job'];
        $started = microtime(true);
        $budget = $seconds ?? $this->stepSeconds;
        $processed = 0;

        try {
            while (($job['phase'] ?? '') !== '' && ($processed === 0 || microtime(true) - $started < $budget)) {
                $rows = $this->rows($source, (string) $job['phase'], (int) $job['cursor'], $job);

                if ($rows === []) {
                    $next = array_search($job['phase'], $job['phases'], true);
                    $job['phase'] = $job['phases'][(int) $next + 1] ?? '';
                    $job['cursor'] = 0;

                    continue;
                }

                foreach ($rows as $row) {
                    $this->work($admin, $source, $text, $job, $runId, $report, $row);
                }

                $job['cursor'] = $job['phase'] === 'favorites' ? (int) $job['cursor'] + count($rows) : (int) end($rows)['id'];
                $processed++;
                $this->tracker->save($runId, 'running', $job, $report->toArray());
                $this->tracker->lock($runId, self::LOCK_SECONDS);
            }
        } catch (Throwable $exception) {
            // What was done is kept; the run can be carried on, which tries the same batch again.
            $this->tracker->save($runId, 'running', $job, $report->toArray());

            throw $exception;
        }

        $done = ($job['phase'] ?? '') === '';
        $this->tracker->save($runId, $done ? 'done' : 'running', $job, $report->toArray());

        if ($done) {
            $this->audit->record($admin->id, 'plugin.efiction_importer.finish', 'plugin', 'efiction-importer', ['run' => $runId, 'counts' => $report->counts], (string) ($job['ip'] ?? ''));
        }

        return ['state' => $done ? 'done' : 'running', 'phase' => (string) ($job['phase'] ?? ''), 'done' => $done, 'busy' => false, 'report' => $report];
    }

    public function cancel(User $admin, int $runId, string $ipAddress): void
    {
        $run = $this->tracker->run($runId);

        if ($run !== null && $run['state'] === 'running') {
            $this->tracker->save($runId, 'cancelled', $run['job'], $run['report']);
            $this->audit->record($admin->id, 'plugin.efiction_importer.cancel', 'plugin', 'efiction-importer', ['run' => $runId], $ipAddress);
        }
    }

    /**
     * Removes exactly what imports made: comments and bookmarks first, then stories (with their chapters), news, pages, terms
     * and last the accounts. Something an import only matched, and anything made by hand, is never touched.
     *
     * @return array<string, int>
     */
    public function removeAll(User $admin, string $ipAddress): array
    {
        $this->tracker->ensureTables();
        $removed = ['comment' => 0, 'bookmark' => 0, 'series' => 0, 'story' => 0, 'news' => 0, 'page' => 0, 'term' => 0, 'user' => 0, 'kept' => 0];

        $this->database->execute(
            'DELETE r FROM {prefix}comment_reports r JOIN {prefix}efiction_items t ON t.kind = \'comment\' AND t.made = 1 AND t.local_id = r.comment_id',
        );
        $removed['comment'] = $this->database->execute(
            'DELETE c FROM {prefix}comments c JOIN {prefix}efiction_items t ON t.kind = \'comment\' AND t.made = 1 AND t.local_id = c.id',
        );
        $removed['bookmark'] = $this->database->execute(
            'DELETE b FROM {prefix}bookmarks b JOIN {prefix}efiction_items t ON t.kind = \'bookmark\' AND t.made = 1 AND t.local_id = b.id',
        );

        // A series goes first, which only drops the grouping: the stories in it are removed below if the import made them.
        foreach ($this->series === null ? [] : $this->tracker->made(Tracker::SERIES) as $item) {
            try {
                $this->series->delete($admin, $item['local_id'], $ipAddress);
                $removed['series']++;
            } catch (Throwable) {
                continue;
            }
        }

        foreach ($this->tracker->made(Tracker::STORY) as $item) {
            try {
                $this->stories->delete($admin, $item['local_id'], $ipAddress);
                $removed['story']++;
            } catch (Throwable) {
                // Already gone by hand, which is what was wanted.
            }
        }

        foreach ($this->tracker->made(Tracker::NEWS) as $item) {
            try {
                $this->news->delete($admin, $item['local_id'], $ipAddress);
                $removed['news']++;
            } catch (Throwable) {
                continue;
            }
        }

        foreach ($this->tracker->made(Tracker::PAGE) as $item) {
            try {
                $this->pages->delete($admin, $item['local_id'], $ipAddress);
                $removed['page']++;
            } catch (Throwable) {
                continue;
            }
        }

        foreach ($this->tracker->made(Tracker::TERM) as $item) {
            try {
                $this->taxonomy->delete($admin, $item['local_id'], $ipAddress);
                $removed['term']++;
            } catch (Throwable) {
                // Still in use by a story written by hand: it stays, since it is part of that story now.
                $removed['kept']++;
            }
        }

        foreach ($this->tracker->made(Tracker::USER) as $item) {
            if ($this->removeUser($item['local_id'])) {
                $removed['user']++;
            } else {
                $removed['kept']++;
            }
        }

        $this->tracker->forgetAll();
        $this->audit->record($admin->id, 'plugin.efiction_importer.remove', 'plugin', 'efiction-importer', $removed, $ipAddress);

        return $removed;
    }

    // ---- the parts -------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $job
     * @return list<array<string, mixed>> rows, each with an "id" that is the cursor for the next batch
     */
    private function rows(Source $source, string $phase, int $cursor, array $job): array
    {
        $limit = (int) (self::BATCH[$phase] ?? 100);

        return match ($phase) {
            'ratings' => $source->hasTable('ratings') ? $source->select('SELECT rid AS id, rating AS name FROM `{p}ratings` WHERE rid > :c ORDER BY rid LIMIT ' . $limit, ['c' => $cursor]) : [],
            'categories' => $source->hasTable('categories') ? $source->select('SELECT catid AS id, category AS name FROM `{p}categories` WHERE catid > :c ORDER BY catid LIMIT ' . $limit, ['c' => $cursor]) : [],
            'characters' => $source->hasTable('characters') ? $source->select('SELECT charid AS id, charname AS name, catid FROM `{p}characters` WHERE charid > :c ORDER BY charid LIMIT ' . $limit, ['c' => $cursor]) : [],
            'classes' => $source->hasTable('classes') ? $source->select('SELECT class_id AS id, class_type, class_name AS name FROM `{p}classes` WHERE class_id > :c ORDER BY class_id LIMIT ' . $limit, ['c' => $cursor]) : [],
            'authors' => $source->select(
                'SELECT a.uid AS id, a.penname, a.email, a.bio, a.date, a.password, a.admincreated, '
                . ($source->hasTable('authorprefs') ? 'COALESCE(p.level, 0)' : '0') . ' AS level, '
                . '(SELECT COUNT(*) FROM `{p}stories` s WHERE s.uid = a.uid) AS stories FROM `{p}authors` a '
                . ($source->hasTable('authorprefs') ? 'LEFT JOIN `{p}authorprefs` p ON p.uid = a.uid ' : '')
                . 'WHERE a.uid > :c ORDER BY a.uid LIMIT ' . $limit,
                ['c' => $cursor],
            ),
            'stories' => $source->select('SELECT sid AS id, title, summary, storynotes, catid, classes, charid, rid, `date`, updated, uid, coauthors, featured, validated, completed FROM `{p}stories` WHERE sid > :c ORDER BY sid LIMIT ' . $limit, ['c' => $cursor]),
            'series' => $source->hasTable('series') && $source->hasTable('inseries') ? $source->select('SELECT seriesid AS id, title, summary, uid FROM `{p}series` WHERE seriesid > :c ORDER BY seriesid LIMIT ' . $limit, ['c' => $cursor]) : [],
            'reviews' => $source->hasTable('reviews') ? $source->select('SELECT reviewid AS id, item, chapid, uid, reviewer, review, `date` FROM `{p}reviews` WHERE type = \'ST\' AND reviewid > :c ORDER BY reviewid LIMIT ' . $limit, ['c' => $cursor]) : [],
            'favorites' => $source->hasTable('favorites') ? array_map(
                static fn (array $row): array => $row + ['id' => (int) $row['uid'] . ':' . (int) $row['item']],
                $source->select('SELECT uid, item FROM `{p}favorites` WHERE type = \'ST\' ORDER BY uid, item LIMIT ' . $limit . ' OFFSET ' . max(0, $cursor)),
            ) : [],
            'news' => $source->hasTable('news') ? $source->select('SELECT nid AS id, author, title, story, `time` FROM `{p}news` WHERE nid > :c ORDER BY nid LIMIT ' . $limit, ['c' => $cursor]) : [],
            'pages' => $this->pageRows($source, $cursor, $limit, (array) ($job['options']['pages'] ?? [])),
            default => [],
        };
    }

    /**
     * @param list<mixed> $wanted
     * @return list<array<string, mixed>>
     */
    private function pageRows(Source $source, int $cursor, int $limit, array $wanted): array
    {
        $ids = array_values(array_filter(array_map('intval', $wanted), static fn (int $id): bool => $id > 0));

        if ($ids === [] || !$source->hasTable('messages')) {
            return [];
        }

        return $source->select(
            'SELECT message_id AS id, message_name, message_title, message_text FROM `{p}messages` WHERE message_id > :c AND message_id IN (' . implode(',', $ids) . ') ORDER BY message_id LIMIT ' . $limit,
            ['c' => $cursor],
        );
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $row
     */
    private function work(User $admin, Source $source, ChapterText $text, array $job, int $runId, Report $report, array $row): void
    {
        $ip = (string) ($job['ip'] ?? '');
        $id = (string) $row['id'];

        try {
            match ((string) $job['phase']) {
                'ratings' => $this->rating($admin, $job, $runId, $report, $row, $ip),
                'categories' => $this->category($admin, $job, $runId, $report, $row, $ip),
                'characters' => $this->character($admin, $runId, $report, $row, $ip),
                'classes' => $this->termClass($admin, $job, $runId, $report, $row, $ip),
                'authors' => $this->author($job, $runId, $report, $row),
                'stories' => $this->story($admin, $source, $text, $job, $runId, $report, $row, $ip),
                'series' => $this->seriesRow($source, $runId, $report, $row),
                'reviews' => $this->review($admin, $job, $runId, $report, $row),
                'favorites' => $this->favorite($runId, $report, $row),
                'news' => $this->newsPost($admin, $runId, $report, $row, $ip),
                'pages' => $this->page($admin, $runId, $report, $row, $ip),
            };
        } catch (Throwable $exception) {
            // One broken row is reported and the rest carries on.
            $report->failed($this->kindOf((string) $job['phase']), $id, 'It could not be imported (' . $exception::class . ').');
        }
    }

    private function kindOf(string $phase): string
    {
        return match ($phase) {
            'ratings', 'categories', 'characters', 'classes' => 'term',
            'authors' => 'user',
            'stories' => 'story',
            'series' => 'series',
            'reviews' => 'comment',
            'favorites' => 'bookmark',
            'news' => 'news',
            default => 'page',
        };
    }

    // ---- classification --------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $row
     */
    private function rating(User $admin, array $job, int $runId, Report $report, array $row, string $ip): void
    {
        $key = 'rating:' . $row['id'];
        $choice = $job['mapping']['ratings'][(int) $row['id']] ?? 'new';

        if ($this->tracker->find(Tracker::TERM, $key) !== null) {
            $report->again('term');
        } elseif ($choice === 'skip') {
            $report->skipped('term', $key, 'You chose to leave it out.');
        } elseif (is_int($choice)) {
            $this->tracker->record(Tracker::TERM, $key, $choice, $runId, false);
            $report->matched('term');
        } else {
            $this->makeTerm($admin, TermType::Rating, (string) $row['name'], 0, $key, $runId, $report, $ip);
        }
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $row
     */
    private function category(User $admin, array $job, int $runId, Report $report, array $row, string $ip): void
    {
        $key = 'cat:' . $row['id'];
        $choice = $job['mapping']['categories'][(int) $row['id']] ?? 'skip';

        if ($this->tracker->find(Tracker::TERM, $key) !== null) {
            $report->again('term');
        } elseif ($choice === 'skip') {
            // Categories nothing uses are the usual case here, so they are counted and not listed.
            $report->left('term');
        } elseif (is_int($choice)) {
            $this->tracker->record(Tracker::TERM, $key, $choice, $runId, false);
            $report->matched('term');
        } else {
            $this->makeTerm($admin, TermType::Fandom, (string) $row['name'], 0, $key, $runId, $report, $ip);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function character(User $admin, int $runId, Report $report, array $row, string $ip): void
    {
        $key = 'char:' . $row['id'];

        if ($this->tracker->find(Tracker::TERM, $key) !== null) {
            $report->again('term');

            return;
        }

        // A character whose fandom was left out is kept, without a fandom, only if a story uses it; simpler and safer to make it
        // under the fandom it had, or under none.
        $parent = $this->tracker->localId(Tracker::TERM, 'cat:' . (int) $row['catid']) ?? 0;
        $this->makeTerm($admin, TermType::Character, (string) $row['name'], $parent, $key, $runId, $report, $ip);
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $row
     */
    private function termClass(User $admin, array $job, int $runId, Report $report, array $row, string $ip): void
    {
        $key = 'class:' . $row['id'];
        $kind = (string) ($job['mapping']['classtypes'][(int) $row['class_type']] ?? 'skip');

        if ($this->tracker->find(Tracker::TERM, $key) !== null) {
            $report->again('term');
        } elseif ($kind === 'skip' || TermType::tryFrom($kind) === null) {
            $report->left('term');
        } else {
            $this->makeTerm($admin, TermType::from($kind), (string) $row['name'], 0, $key, $runId, $report, $ip);
        }
    }

    /**
     * Uses the term with that name when there is one, so a list that was already set up here is not doubled.
     */
    private function makeTerm(User $admin, TermType $type, string $rawName, int $parent, string $key, int $runId, Report $report, string $ip): void
    {
        [$name] = Text::utf8($rawName);
        // A comma separates names when several are typed together, so it cannot be part of one.
        $name = trim((string) preg_replace('/\s+/u', ' ', str_replace(',', ' ', Text::plain($name))));

        try {
            $name = $this->taxonomy->cleanName(Text::cut($name, TaxonomyService::NAME_MAX));
        } catch (Throwable $exception) {
            $report->failed('term', $key, 'The name was refused: ' . $exception->getMessage());

            return;
        }

        $existing = $this->taxonomy->resolve($type, $name, $parent);

        if ($existing !== null) {
            $this->tracker->record(Tracker::TERM, $key, $existing->id, $runId, false);
            $report->matched('term');

            return;
        }

        try {
            $term = $this->taxonomy->create($admin, $type, $name, $parent, $ip);
        } catch (Throwable $exception) {
            $report->failed('term', $key, 'It could not be made: ' . $exception->getMessage());

            return;
        }

        $this->tracker->record(Tracker::TERM, $key, $term->id, $runId);
        $report->made('term');
    }

    // ---- accounts --------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $row
     */
    private function author(array $job, int $runId, Report $report, array $row): void
    {
        $uid = (string) $row['id'];

        if ($this->tracker->find(Tracker::USER, $uid) !== null) {
            $report->again('user');

            return;
        }

        if ((int) $row['level'] > 0) {
            $report->listed('Staff in eFiction, no role was given here (eFiction id)', $uid);
        }

        if ((int) $row['admincreated'] > 0) {
            $report->listed('Accounts an eFiction administrator made (eFiction id)', $uid);
        }

        [$penname] = Text::utf8((string) $row['penname']);
        $displayName = AccountRules::normalizeDisplayName(Text::plain($penname));

        if (AccountRules::displayNameProblem($displayName) !== null) {
            $displayName = 'Member ' . $uid;
            $report->add('Accounts that needed a made-up display name');
        }

        $email = AccountRules::normalizeEmail((string) $row['email']);
        $real = AccountRules::emailProblem($email) === null;

        if ($real && ($existing = $this->users->findByEmail($email)) !== null) {
            $this->tracker->record(Tracker::USER, $uid, $existing->id, $runId, false);
            $report->matched('user');

            return;
        }

        if (!$real) {
            // The account needs an address to exist; this one reaches nobody, and its owner can be found by the eFiction id.
            $email = 'efiction-member-' . $uid . '@example.invalid';
            $report->add('Accounts with no usable email address');
        }

        $hash = (string) $row['password'];
        $kept = (password_get_info($hash)['algo'] ?? null) !== null;
        $role = (int) $row['stories'] > 0 ? UserRole::Author : UserRole::Reader;

        try {
            // The private login name is made up and never the pen name, as for every account here.
            $user = $this->users->create('ef-' . bin2hex(random_bytes(6)), $displayName, $email, bin2hex(random_bytes(12)) . 'Aa1-', $role, UserStatus::Active);
        } catch (DuplicateAccountException) {
            $existing = $this->users->findByEmail($email);

            if ($existing !== null) {
                $this->tracker->record(Tracker::USER, $uid, $existing->id, $runId, false);
                $report->matched('user');
            } else {
                $report->failed('user', $uid, 'The account could not be made.');
            }

            return;
        } catch (InvalidArgumentException $exception) {
            $report->failed('user', $uid, 'The account was refused: ' . $exception->getMessage());

            return;
        }

        $created = Text::date($row['date']);
        // A hash eFiction already kept with password_hash() is taken as it is; anything else (its older MD5) is never stored, and
        // the account keeps the unguessable password it was made with until the person sets one by the usual reset.
        $this->database->execute(
            'UPDATE {prefix}users SET ' . ($kept ? 'password_hash = :hash, ' : '') . 'email_verified_at = :verified, created_at = :created WHERE id = :id',
            ($kept ? ['hash' => $hash] : []) + [
                'verified' => $real ? date('Y-m-d H:i:s') : null,
                'created' => ($created !== null && $created <= new \DateTimeImmutable() ? $created : new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id' => $user->id,
            ],
        );

        if (!empty($job['options']['bio']) && trim((string) $row['bio']) !== '') {
            [$bio] = Text::utf8((string) $row['bio']);
            $this->database->execute(
                'UPDATE {prefix}users SET about = :about, about_format = \'html\' WHERE id = :id',
                ['about' => $this->sanitizer->clean(Text::toHtml(Text::cut(Text::plain($bio), 5000))), 'id' => $user->id],
            );
        }

        $report->add($kept ? 'Accounts that keep their old password' : 'Accounts that must set a new password');
        $this->tracker->record(Tracker::USER, $uid, $user->id, $runId);
        $report->made('user');
    }

    // ---- stories ---------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $row
     */
    private function story(User $admin, Source $source, ChapterText $text, array $job, int $runId, Report $report, array $row, string $ip): void
    {
        $sid = (string) $row['id'];
        $found = $this->tracker->find(Tracker::STORY, $sid);

        if ($found !== null) {
            if ($found['complete']) {
                $report->again('story');

                return;
            }

            // The run was cut off in the middle of this story: what there is of it is removed and it is made again whole.
            $this->discardStory($admin, $found['local_id'], $ip);
            $this->tracker->forget(Tracker::STORY, $sid);
        }

        $ownerId = $this->tracker->localId(Tracker::USER, (string) $row['uid']);
        $owner = $ownerId === null ? null : $this->users->findById($ownerId);

        if ($owner === null) {
            $report->skipped('story', $sid, 'Its author is not in the archive.');

            return;
        }

        $chapters = $source->select('SELECT chapid, title, inorder, notes, storytext, endnotes, validated, uid FROM `{p}chapters` WHERE sid = :sid ORDER BY inorder, chapid', ['sid' => (int) $row['id']]);

        if ($chapters === []) {
            $report->skipped('story', $sid, 'It has no chapters.');

            return;
        }

        $readable = [];

        foreach ($chapters as $chapter) {
            $source_text = $text->find($chapter);

            if ($source_text['text'] === '') {
                $report->skipped('chapter', (string) $chapter['chapid'], 'No text was found for it, in the database or in the stories folder.');

                continue;
            }

            if ($source_text['converted']) {
                $report->add('Chapters whose character set was repaired');
            }

            $readable[] = ['row' => $chapter, 'text' => $source_text['text']];
        }

        if ($readable === []) {
            $report->skipped('story', $sid, 'None of its chapters has any text.');

            return;
        }

        [$title] = Text::utf8((string) $row['title']);
        [$summary] = Text::utf8((string) $row['summary']);
        [$notes] = Text::utf8((string) $row['storynotes']);
        $language = (string) ($job['options']['language'] ?? 'en');

        try {
            $story = $this->stories->create($owner, [
                'title' => Text::cut(Text::plain($title), StoryService::TITLE_MAX) ?: 'Untitled story',
                'summary' => Text::cut(Text::plain($summary), StoryService::SUMMARY_MAX),
                // The nearest place for eFiction's story notes: shown after the story, as the notes a writer adds at the end.
                'end_notes' => Text::cut(Text::plain($notes), StoryService::END_NOTES_MAX),
                'language' => preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?\z/', $language) === 1 ? $language : 'en',
                'work_status' => ((int) $row['completed'] > 0 ? WorkStatus::Completed : WorkStatus::Ongoing)->value,
            ], $ip);
        } catch (Throwable $exception) {
            $report->failed('story', $sid, $exception instanceof \LumoraFiction\Services\StoryPermissionException
                ? 'Its author may not write stories here. Check who may publish under Settings > Archive, then run the import again.'
                : 'The story was refused: ' . $this->reasons($exception));

            return;
        }

        // Recorded before the chapters are written, so a story cut off part-way can be found and redone.
        $this->tracker->record(Tracker::STORY, $sid, $story->id, $runId, true, false);
        $chapterIds = [];
        $publishable = [];

        try {
            foreach ($readable as $item) {
                $chapterRow = $item['row'];
                [$chapterTitle] = Text::utf8((string) $chapterRow['title']);
                [$chapterNotes] = Text::utf8((string) $chapterRow['notes']);
                [$chapterEnd] = Text::utf8((string) ($chapterRow['endnotes'] ?? ''));

                try {
                    $chapter = $this->chapters->add($owner, $story->id, [
                        'title' => Text::cut(Text::plain($chapterTitle), ChapterService::TITLE_MAX),
                        'content' => $this->sanitizer->clean(Text::toHtml($item['text'])),
                        'format' => 'html',
                        'notes' => Text::cut(Text::plain($chapterNotes), ChapterService::NOTES_MAX),
                        'end_notes' => Text::cut(Text::plain($chapterEnd), ChapterService::END_NOTES_MAX),
                    ], $ip);
                } catch (Throwable $exception) {
                    $report->failed('chapter', (string) $chapterRow['chapid'], 'The chapter was refused: ' . $this->reasons($exception));

                    continue;
                }

                $chapterIds[] = $chapter->id;
                $this->tracker->record(Tracker::CHAPTER, (string) $chapterRow['chapid'], $chapter->id, $runId);
                $report->made('chapter');

                if ((int) $chapterRow['validated'] > 0) {
                    $publishable[] = $chapter->id;
                }
            }

            if ($chapterIds === []) {
                throw new \RuntimeException('none');
            }

            $selection = $this->selection($row, $report);
            $this->stories->classify($owner, $story->id, $selection, $ip);
        } catch (Throwable $exception) {
            $this->discardStory($admin, $story->id, $ip);
            $this->tracker->forget(Tracker::STORY, $sid);
            $report->failed('story', $sid, $exception->getMessage() === 'none' ? 'None of its chapters could be saved.' : 'The story could not be saved, so nothing of it was kept: ' . $this->reasons($exception));

            return;
        }

        $this->publish($admin, $owner, $story->id, $chapterIds, $publishable, (int) $row['validated'] > 0, (string) ($job['options']['unvalidated'] ?? 'draft'), $report, $sid, $ip);

        $created = Text::date($row['date']);
        $updated = Text::date($row['updated']);
        $this->stories->applyDates($admin, $story->id, $created, $updated, $created);

        foreach ($chapterIds as $chapterId) {
            // eFiction does not date a chapter, so each takes the story's dates.
            $this->chapters->applyDates($admin, $chapterId, $created, $updated, $created);
        }

        if (trim((string) $row['coauthors']) !== '' && trim((string) $row['coauthors']) !== '0') {
            $report->listed('Stories with co-authors, written to the main author only (eFiction id)', $sid);
        }

        if ((int) $row['featured'] > 0) {
            $report->add('Featured stories, not carried over');
        }

        $this->tracker->complete(Tracker::STORY, $sid);
        $report->made('story');
        $this->hooks?->doAction('efiction_story_imported', $story->id, $owner->id, $sid);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, list<int>>
     */
    private function selection(array $row, Report $report): array
    {
        $selection = [];
        $add = function (TermType $type, string $key) use (&$selection): void {
            $local = $this->tracker->localId(Tracker::TERM, $key);

            if ($local !== null) {
                $selection[$type->value][$local] = $local;
            }
        };

        foreach (Survey::ids((string) $row['rid']) as $id) {
            $add(TermType::Rating, 'rating:' . $id);
        }

        foreach (Survey::ids((string) $row['catid']) as $id) {
            $add(TermType::Fandom, 'cat:' . $id);
        }

        foreach (Survey::ids((string) $row['charid']) as $id) {
            $add(TermType::Character, 'char:' . $id);
        }

        foreach (Survey::ids((string) ($row['classes'] ?? '')) as $id) {
            $local = $this->tracker->localId(Tracker::TERM, 'class:' . $id);
            $term = $local === null ? null : $this->taxonomy->find($local);

            if ($term !== null) {
                $selection[$term->type->value][$term->id] = $term->id;
            }
        }

        $cleaned = [];

        foreach ($selection as $type => $ids) {
            $limit = TermType::from($type)->maximumPerStory();

            if (count($ids) > $limit) {
                $report->add('Stories that had more ' . TermType::from($type)->pluralLabel() . ' than a story may carry here');
            }

            $cleaned[$type] = array_slice(array_values($ids), 0, $limit);
        }

        return $cleaned;
    }

    /**
     * What eFiction's "validated" becomes. A validated story is published by the administrator running the import, which is
     * what staff always may do; an unvalidated one is a draft, or goes to the approval queue when the site's rule for that
     * author would hold it anyway, so the import never publishes what the rule would have held.
     *
     * @param list<int> $chapterIds
     * @param list<int> $publishable
     */
    private function publish(User $admin, User $owner, int $storyId, array $chapterIds, array $publishable, bool $validated, string $unvalidated, Report $report, string $sid, string $ip): void
    {
        $actor = $validated ? $admin : $owner;

        if (!$validated && $unvalidated !== 'approval') {
            return;
        }

        try {
            if (!$validated && $this->stories->publishesDirectly($owner, $this->stories->find($storyId) ?? throw new \RuntimeException('gone'))) {
                $report->note('A story that was waiting for approval in eFiction was left as a draft, because its author would publish without review here.');

                return;
            }

            foreach ($publishable as $chapterId) {
                $this->chapters->publish($admin, $chapterId, $ip);
            }

            $this->stories->publish($actor, $storyId, $ip);
        } catch (Throwable $exception) {
            foreach ($chapterIds as $chapterId) {
                try {
                    $this->chapters->unpublish($admin, $chapterId, $ip);
                } catch (Throwable) {
                    continue;
                }
            }

            $report->note('A story could not be published and was left as a draft: ' . $this->reasons($exception));
            $report->add('Stories left as drafts because they could not be published');
        }
    }

    private function discardStory(User $admin, int $storyId, string $ip): void
    {
        try {
            $this->stories->delete($admin, $storyId, $ip);
        } catch (Throwable) {
            // Nothing more can be done here; the draft stays where its author can delete it.
        }
    }

    // ---- the optional parts ----------------------------------------------------------------------------

    /**
     * A series becomes a series here, owned by its eFiction author and holding the imported stories in eFiction's order.
     * A series is one level and holds one author's stories, so a link to another series is dropped and a story by someone
     * else, or one already in a series here, is left out; each case is counted and explained in the report.
     *
     * @param array<string, mixed> $row
     */
    private function seriesRow(Source $source, int $runId, Report $report, array $row): void
    {
        $id = (string) $row['id'];

        if ($this->tracker->find(Tracker::SERIES, $id) !== null) {
            $report->again('series');

            return;
        }

        $members = $source->select(
            'SELECT sid FROM `{p}inseries` WHERE seriesid = :id AND confirmed = 1 AND subseriesid = 0 ORDER BY inorder, sid',
            ['id' => (int) $row['id']],
        );

        if ((int) $source->value('SELECT COUNT(*) FROM `{p}inseries` WHERE seriesid = :id AND subseriesid <> 0', ['id' => (int) $row['id']]) > 0) {
            $report->note('A series that holds another series was imported without that link, because a series here is one level.');
        }

        $stories = [];

        foreach ($members as $member) {
            $local = $this->tracker->localId(Tracker::STORY, (string) $member['sid']);

            if ($local === null) {
                $report->add('Stories left out of a series');

                continue;
            }

            $stories[] = $local;
        }

        if ($stories === [] || $this->series === null) {
            $report->skipped('series', $id, $stories === [] ? 'None of its stories was imported.' : 'Series are not available.');

            return;
        }

        $ownerId = $this->tracker->localId(Tracker::USER, (string) $row['uid'])
            ?? (int) $this->database->fetchColumn('SELECT owner_id FROM {prefix}stories WHERE id = :id', ['id' => $stories[0]]);
        $owner = $ownerId > 0 ? $this->users->findById($ownerId) : null;

        if ($owner === null) {
            $report->skipped('series', $id, 'Its author is not in the archive.');

            return;
        }

        [$title] = Text::utf8((string) $row['title']);
        [$summary] = Text::utf8((string) ($row['summary'] ?? ''));

        try {
            $series = $this->series->create($owner, [
                'title' => Text::cut(trim($title), SeriesService::TITLE_MAX),
                'summary' => Text::cut(Text::plain($summary), SeriesService::SUMMARY_MAX),
            ]);
        } catch (GroupingException $exception) {
            $report->skipped('series', $id, $exception->getMessage() . ' ' . implode(' ', $exception->errors));

            return;
        }

        $this->tracker->record(Tracker::SERIES, $id, $series->id, $runId);
        $report->made('series');

        foreach ($stories as $storyId) {
            try {
                $this->series->addStory($owner, $series->id, $storyId);
            } catch (GroupingException $exception) {
                $report->add('Stories left out of a series');
                $report->note($exception->getMessage());
            }
        }
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $row
     */
    private function review(User $admin, array $job, int $runId, Report $report, array $row): void
    {
        $id = (string) $row['id'];
        $guest = (int) $row['uid'] <= 0;
        $members = (bool) ($job['options']['reviews'] ?? false);
        $guests = (bool) ($job['options']['guest_reviews'] ?? false);

        // One pass reads both kinds. A member's review is left alone, without a line in the report, when only guests were
        // ticked; a guest's is reported as skipped when only members were.
        if (!$guest && !$members || $guest && !$members && !$guests) {
            return;
        }

        if ($this->tracker->find(Tracker::COMMENT, $id) !== null) {
            $report->again('comment');

            return;
        }

        if ($guest && !$guests) {
            $report->skipped('comment', $id, 'A guest wrote it, and guest reviews were not ticked.');

            return;
        }

        if ($guest) {
            $this->guestReview($admin, $runId, $report, $row);

            return;
        }

        $userId = $this->tracker->localId(Tracker::USER, (string) $row['uid']);
        $storyId = $this->tracker->localId(Tracker::STORY, (string) $row['item']);

        if ($userId === null || $storyId === null) {
            $report->skipped('comment', $id, $userId === null ? 'Its writer is not in the archive.' : 'Its story was not imported.');

            return;
        }

        $chapterId = $this->tracker->localId(Tracker::CHAPTER, (string) $row['chapid']);

        // A review of the whole story goes under its first chapter, since a comment belongs to a chapter here.
        $chapterId ??= (int) $this->database->fetchColumn('SELECT id FROM {prefix}chapters WHERE story_id = :story ORDER BY number LIMIT 1', ['story' => $storyId]);

        [$review] = Text::utf8((string) $row['review']);
        $body = Text::cut(Text::plain($review), CommentService::BODY_MAX);

        if ($chapterId <= 0 || $body === '') {
            $report->skipped('comment', $id, $body === '' ? 'It is empty.' : 'Its chapter was not imported.');

            return;
        }

        $date = Text::date($row['date']) ?? new \DateTimeImmutable();
        $commentId = (int) $this->database->insertGetId(
            'INSERT INTO {prefix}comments (story_id, chapter_id, user_id, body, status, report_count, created_at) VALUES (:story, :chapter, :user, :body, \'published\', 0, :created)',
            ['story' => $storyId, 'chapter' => $chapterId, 'user' => $userId, 'body' => $body, 'created' => $date->format('Y-m-d H:i:s')],
        );
        $this->tracker->record(Tracker::COMMENT, $id, $commentId, $runId);
        $report->made('comment');
    }

    /**
     * A guest's review becomes a comment owned by the importing Administrator and shown under the reviewer's own name. It
     * goes through the comment service, so a story or chapter with comments switched off is refused and reported.
     *
     * @param array<string, mixed> $row
     */
    private function guestReview(User $admin, int $runId, Report $report, array $row): void
    {
        $id = (string) $row['id'];
        $storyId = $this->tracker->localId(Tracker::STORY, (string) $row['item']);

        if ($storyId === null || $this->comments === null) {
            $this->skipGuest($report, $id, $storyId === null ? 'Its story was not imported.' : 'Comments are not available.');

            return;
        }

        $chapterId = $this->tracker->localId(Tracker::CHAPTER, (string) $row['chapid']);
        $chapterId ??= (int) $this->database->fetchColumn('SELECT id FROM {prefix}chapters WHERE story_id = :story ORDER BY number LIMIT 1', ['story' => $storyId]);

        [$review] = Text::utf8((string) $row['review']);
        $body = Text::cut(Text::plain($review), CommentService::BODY_MAX);

        if ($chapterId <= 0 || $body === '') {
            $this->skipGuest($report, $id, $body === '' ? 'It is empty.' : 'Its chapter was not imported.');

            return;
        }

        [$name] = Text::utf8((string) ($row['reviewer'] ?? ''));

        try {
            $comment = $this->comments->importGuest($admin, $chapterId, $name, $body, Text::date($row['date']) ?? new \DateTimeImmutable());
        } catch (CommentException $exception) {
            $this->skipGuest($report, $id, $exception->getMessage());

            return;
        }

        $this->tracker->record(Tracker::COMMENT, $id, $comment->id, $runId);
        $report->made('comment');
        $report->add('Guest reviews imported');
    }

    private function skipGuest(Report $report, string $id, string $reason): void
    {
        $report->skipped('comment', $id, 'A guest review: ' . $reason);
        $report->add('Guest reviews skipped');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function favorite(int $runId, Report $report, array $row): void
    {
        $id = (string) $row['id'];

        if ($this->tracker->find(Tracker::BOOKMARK, $id) !== null) {
            $report->again('bookmark');

            return;
        }

        $userId = $this->tracker->localId(Tracker::USER, (string) $row['uid']);
        $storyId = $this->tracker->localId(Tracker::STORY, (string) $row['item']);

        if ($userId === null || $storyId === null) {
            $report->skipped('bookmark', $id, $userId === null ? 'The member is not in the archive.' : 'The story was not imported.');

            return;
        }

        $where = 'user_id = :user AND story_id = :story AND chapter_id = 0';
        $existing = (int) $this->database->fetchColumn('SELECT id FROM {prefix}bookmarks WHERE ' . $where, ['user' => $userId, 'story' => $storyId]);

        // A bookmark the reader already had is matched and stays theirs: removing the import never removes it.
        if ($existing > 0) {
            $this->tracker->record(Tracker::BOOKMARK, $id, $existing, $runId, false);
            $report->matched('bookmark');

            return;
        }

        $bookmarkId = (int) $this->database->insertGetId(
            'INSERT INTO {prefix}bookmarks (user_id, story_id, chapter_id, created_at) VALUES (:user, :story, 0, :now)',
            ['user' => $userId, 'story' => $storyId, 'now' => date('Y-m-d H:i:s')],
        );
        $this->tracker->record(Tracker::BOOKMARK, $id, $bookmarkId, $runId);
        $report->made('bookmark');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function newsPost(User $admin, int $runId, Report $report, array $row, string $ip): void
    {
        $id = (string) $row['id'];

        if ($this->tracker->find(Tracker::NEWS, $id) !== null) {
            $report->again('news');

            return;
        }

        [$title] = Text::utf8((string) $row['title']);
        [$body] = Text::utf8((string) $row['story']);
        $actor = $this->newsAuthor((string) $row['author'], $admin);

        try {
            $post = $this->news->create($actor, ['title' => Text::cut(Text::plain($title), NewsService::TITLE_MAX) ?: 'News', 'content' => $this->sanitizer->clean(Text::toHtml($body)), 'format' => 'html', 'pinned' => false], $ip);
            $this->news->publish($actor, $post->id, $ip);
        } catch (Throwable $exception) {
            $report->failed('news', $id, 'The post was refused: ' . $this->reasons($exception));

            return;
        }

        // The service dates a post by when it is made; an archive's news keeps the day it was written.
        $date = Text::date($row['time']);

        if ($date !== null && $date <= new \DateTimeImmutable()) {
            $stamp = $date->format('Y-m-d H:i:s');
            // One placeholder per value: the server's own prepared statements do not allow a name to be used twice.
            $this->database->execute(
                'UPDATE {prefix}news_posts SET created_at = :created, updated_at = :updated, published_at = :published WHERE id = :id',
                ['created' => $stamp, 'updated' => $stamp, 'published' => $stamp, 'id' => $post->id],
            );
        }

        $this->tracker->record(Tracker::NEWS, $id, $post->id, $runId);
        $report->made('news');
    }

    /**
     * eFiction names the writer of a news post as text. It is the imported member with that pen name when that member may
     * write news here, and the administrator running the import otherwise.
     */
    private function newsAuthor(string $name, User $fallback): User
    {
        $name = AccountRules::normalizeDisplayName($name);

        if ($name === '') {
            return $fallback;
        }

        $id = $this->database->fetchColumn(
            'SELECT u.id FROM {prefix}users u JOIN {prefix}efiction_items t ON t.kind = \'user\' AND t.local_id = u.id WHERE u.display_name = :name LIMIT 1',
            ['name' => $name],
        );
        $user = $id === false || $id === null ? null : $this->users->findById((int) $id);

        return $user !== null && $user->can(Capability::PublishNews) ? $user : $fallback;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function page(User $admin, int $runId, Report $report, array $row, string $ip): void
    {
        $id = (string) $row['id'];

        if ($this->tracker->find(Tracker::PAGE, $id) !== null) {
            $report->again('page');

            return;
        }

        [$title] = Text::utf8((string) $row['message_title']);
        [$body] = Text::utf8((string) $row['message_text']);
        $name = (string) $row['message_name'];
        $title = Text::cut(Text::plain($title), PageService::TITLE_MAX);

        try {
            $page = $this->pages->create($admin, [
                'title' => $title !== '' ? $title : ucfirst($name),
                'slug' => Slug::fromText($name, PageService::SLUG_MAX, 'page-' . $id),
                'content' => $this->sanitizer->clean(Text::toHtml($body)),
                'format' => 'html',
                'in_menu' => false,
                'in_footer' => false,
                'sort_order' => 0,
                'description' => '',
                'noindex' => false,
            ], $ip);
        } catch (Throwable $exception) {
            $report->skipped('page', $id, 'It was not made as a page: ' . $this->reasons($exception));

            return;
        }

        // A draft: eFiction's own welcome and copyright texts hold its template placeholders and need a look before anyone reads them.
        $this->tracker->record(Tracker::PAGE, $id, $page->id, $runId);
        $report->made('page');
    }

    // ---- removal and small helpers ---------------------------------------------------------------------

    /**
     * An imported account goes only when it still has nothing of its own: a story or comment made since the import keeps it.
     */
    private function removeUser(int $userId): bool
    {
        $busy = (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}stories WHERE owner_id = :id', ['id' => $userId])
            + (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}comments WHERE user_id = :id', ['id' => $userId]);

        if ($busy > 0) {
            return false;
        }

        foreach (['bookmarks', 'reading_progress', 'story_follows', 'user_tokens', 'comment_accounts', 'notifications'] as $table) {
            $this->database->execute('DELETE FROM {prefix}' . $table . ' WHERE user_id = :id', ['id' => $userId]);
        }

        return $this->database->execute('DELETE FROM {prefix}users WHERE id = :id', ['id' => $userId]) === 1;
    }

    private function reasons(Throwable $exception): string
    {
        // The story, page and news services all name each problem in an "errors" list.
        if (property_exists($exception, 'errors') && is_array($exception->errors) && $exception->errors !== []) {
            return implode(' ', array_map('strval', $exception->errors));
        }

        return $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class;
    }
}
