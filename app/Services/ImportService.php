<?php

/**
 * Lets an author bring their own stories across from an OTW-Archive site (Archive of Our Own, Squidgeworld and the others).
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
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Content\HtmlSanitizer;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Http\UploadedFile;
use LumoraFiction\Core\Import\GeneralHtmlParser;
use LumoraFiction\Core\Import\ImportException;
use LumoraFiction\Core\Import\OtwChapter;
use LumoraFiction\Core\Import\OtwDownloadParser;
use LumoraFiction\Core\Import\OtwWork;
use LumoraFiction\Core\Import\RatingScale;
use LumoraFiction\Core\Import\UploadReader;
use LumoraFiction\Helpers\WordCounter;
use LumoraFiction\Models\ContentFormat;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\User;
use LumoraFiction\Models\WorkStatus;
use Throwable;

/**
 * Every story is written through the story, chapter, taxonomy, series and collection services, so an imported story obeys the same
 * sanitiser, rating rule, approval flow and visibility as one typed in by hand. The upload is read into a plan first and kept for an
 * hour in a private folder; nothing is written to the archive until the author has seen the preview and confirmed it. A story either
 * arrives with all its chapters or is removed again, since the database layer cannot nest a transaction around the services' own.
 */
final class ImportService
{
    private const PLAN_TTL = 7200;

    private const PLAN_BYTES_MAX = 60_000_000;

    private const SOURCE_NOTE = 'Originally published at ';

    private const LOCK_SECONDS = 60;

    public function __construct(
        private readonly Database $database,
        private readonly Configuration $config,
        private readonly AuditLogService $audit,
        private readonly StoryService $stories,
        private readonly ChapterService $chapters,
        private readonly TaxonomyService $taxonomy,
        private readonly SeriesService $series,
        private readonly CollectionService $collections,
        private readonly HtmlSanitizer $sanitizer,
        private readonly OtwDownloadParser $parser,
        private readonly UploadReader $reader,
        private readonly string $workDirectory,
        private readonly ?HookManager $hooks = null,
        private readonly float $stepSeconds = 8.0,
        private readonly ?GeneralHtmlParser $general = null,
    ) {
    }

    public function allowed(User $user): bool
    {
        return ImportSettings::mayImport($this->config, $user);
    }

    /**
     * Reads the uploaded files and keeps what they hold for the preview.
     *
     * Each file goes to the reader that suits it: a download from an OTW-Archive site to the parser that trusts its
     * details, anything else (or any file, when the author asked) to the general HTML reader.
     *
     * @param list<UploadedFile> $uploads
     * @param array{general?: bool, split?: string, combine?: bool} $options force the general reader, how a general file is cut into chapters, and whether several general files are chapters of one story
     * @return string the token of the preview
     * @throws ImportException when nothing could be read or the person may not import
     */
    public function begin(User $user, array $uploads, string $pseuds, array $options = []): string
    {
        $this->requireAllowed($user);
        $this->sweep();

        if ($uploads === []) {
            throw new ImportException('Choose the file you want to import.');
        }

        $force = ($options['general'] ?? false) === true;
        $split = isset(GeneralHtmlParser::SPLITS[$options['split'] ?? '']) ? (string) $options['split'] : 'none';
        $combine = ($options['combine'] ?? true) === true;
        $works = [];
        $sources = [];
        $splits = [];
        $refused = [];
        $files = [];

        foreach ($this->reader->read($uploads, true) as $row) {
            if ($row['html'] === null) {
                $refused[] = ['name' => $row['name'], 'error' => $row['error']];

                continue;
            }

            if ($force || !$this->parser->recognises($row['html'])) {
                $files[] = ['name' => $row['name'], 'html' => $row['html'], 'converted' => ($row['converted'] ?? false) === true];

                continue;
            }

            // The download reader trusts a file's details, so text it cannot be sure of is refused, not guessed at.
            if (($row['converted'] ?? false) === true) {
                $refused[] = ['name' => $row['name'], 'error' => 'This file is not UTF-8 text. Download the work again as HTML and upload that file.'];

                continue;
            }

            try {
                $works[] = $this->parser->parse($row['html'], $row['name'])->toArray();
            } catch (ImportException $exception) {
                $refused[] = ['name' => $row['name'], 'error' => $exception->getMessage()];
            }
        }

        foreach ($this->groupGeneral($files, $combine) as $group) {
            try {
                $work = $this->readGeneral($group, $split);
            } catch (ImportException $exception) {
                $refused[] = ['name' => (string) ($group[0]['name'] ?? ''), 'error' => $exception->getMessage()];

                continue;
            }

            $sources[count($works)] = array_map(static fn (array $file): array => ['name' => $file['name'], 'html' => $file['html'], 'converted' => $file['converted']], $group);
            $splits[count($works)] = $split;
            $works[] = $work->toArray();
        }

        if ($works === []) {
            throw new ImportException($refused[0]['error'] ?? 'Nothing in that upload could be imported.');
        }

        $plan = json_encode(['user' => $user->id, 'created' => time(), 'pseuds' => mb_substr($pseuds, 0, 300, 'UTF-8'), 'works' => $works, 'sources' => $sources, 'splits' => $splits, 'refused' => $refused], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($plan === false || strlen($plan) > self::PLAN_BYTES_MAX) {
            throw new ImportException('That upload is too large to import in one go. Import it in smaller parts.');
        }

        $token = bin2hex(random_bytes(16));
        $this->ensureDirectory();

        if (file_put_contents($this->planPath($token), $plan, LOCK_EX) === false) {
            throw new ImportException('The upload could not be kept for the preview. Please try again.');
        }

        return $token;
    }

    /**
     * What the preview page shows, worked out afresh each time so a term added meanwhile is noticed.
     *
     * @return array<string, mixed>|null null when the token is not this person's or has expired
     */
    public function preview(User $user, string $token): ?array
    {
        $plan = $this->loadPlan($user, $token);

        if ($plan === null) {
            return null;
        }

        $works = [];
        $ratings = [];
        $warnings = [];
        $categories = [];
        $seriesGroups = [];
        $collectionGroups = [];
        $ownNames = $this->ownNames($user, (string) $plan['pseuds']);
        $siteRatings = $this->siteRatings();

        foreach ($plan['works'] as $index => $data) {
            $work = OtwWork::fromArray($data);
            $classified = $this->classification($work);
            $duplicate = $this->duplicateOf($user, $work);

            if ($work->rating !== '') {
                $ratings[$this->key($work->rating)] ??= ['key' => $this->key($work->rating), 'source' => $work->rating, 'count' => 0, 'suggested' => $this->suggestRating($work->rating, $siteRatings), 'exact' => $this->resolvesTo(TermType::Rating, $work->rating)];
                $ratings[$this->key($work->rating)]['count']++;
            }

            foreach ($work->warnings as $name) {
                $warnings[$this->key($name)] ??= ['key' => $this->key($name), 'source' => $name, 'count' => 0, 'suggested' => $this->resolvesTo(TermType::Warning, $name), 'exact' => true];
                $warnings[$this->key($name)]['count']++;
            }

            if ($this->taxonomy->isEnabled(TermType::RelationshipCategory)) {
                foreach ($work->categories as $name) {
                    $categories[$this->key($name)] ??= ['key' => $this->key($name), 'source' => $name, 'count' => 0, 'suggested' => $this->resolvesTo(TermType::RelationshipCategory, $name), 'exact' => true];
                    $categories[$this->key($name)]['count']++;
                }
            }

            if ($work->seriesName !== '' && GroupingSettings::seriesEnabled($this->config)) {
                $seriesGroups[$this->key($work->seriesName)] ??= ['key' => $this->key($work->seriesName), 'name' => $work->seriesName, 'count' => 0];
                $seriesGroups[$this->key($work->seriesName)]['count']++;
            }

            if (GroupingSettings::collectionsEnabled($this->config)) {
                foreach ($work->collections as $name) {
                    $collectionGroups[$this->key($name)] ??= ['key' => $this->key($name), 'name' => $name, 'count' => 0];
                    $collectionGroups[$this->key($name)]['count']++;
                }
            }

            $works[] = [
                'index' => (int) $index,
                'work' => $work,
                'terms' => $classified['terms'],
                'notes' => array_merge($classified['notes'], $this->textNotes($work)),
                'duplicate' => $duplicate,
                'bylineWarning' => $work->byline !== '' && !$this->bylineMatches($work->byline, $ownNames),
                'sourceNote' => $work->sourceUrl !== '',
                'general' => $work->isGeneral(),
                'split' => (string) ($plan['splits'][$index] ?? 'none'),
                'canSplit' => $work->isGeneral() && is_array($plan['sources'][$index] ?? null),
                'files' => $work->isGeneral() && is_array($plan['sources'][$index] ?? null) ? count($plan['sources'][$index]) : 1,
                'chapterList' => array_map(static fn (OtwChapter $chapter): array => ['number' => $chapter->number, 'title' => $chapter->title, 'words' => WordCounter::count($chapter->html, ContentFormat::Html)], $work->chapters),
            ];
        }

        $kinds = [];

        foreach ([TermType::Fandom, TermType::Character, TermType::Relationship, TermType::Tag] as $type) {
            if ($this->taxonomy->isEnabled($type)) {
                $kinds[$type->value] = ['label' => $this->taxonomy->labels()->plural($type), 'proposable' => $this->taxonomy->writersMayPropose($type)];
            }
        }

        $ownSeries = array_map(static fn ($series): array => ['id' => $series->id, 'title' => $series->title], $this->series->ownedBy($user->id));
        $ownCollections = $this->ownCollections($user);

        foreach ($seriesGroups as $key => $group) {
            $seriesGroups[$key]['default'] = $this->matchByTitle($ownSeries, $group['name']) ?? ($group['count'] > 1 ? 'new' : 'none');
        }

        foreach ($collectionGroups as $key => $group) {
            $collectionGroups[$key]['default'] = $this->matchByTitle($ownCollections, $group['name']) ?? 'none';
        }

        return [
            'token' => $token,
            'works' => $works,
            'refused' => $plan['refused'],
            'ratings' => array_values($ratings),
            'warnings' => array_values($warnings),
            'categories' => array_values($categories),
            'ratingOptions' => $this->options(TermType::Rating),
            'warningOptions' => $this->options(TermType::Warning),
            'categoryOptions' => $this->options(TermType::RelationshipCategory),
            'genreOptions' => $this->taxonomy->isEnabled(TermType::Genre) ? $this->options(TermType::Genre) : [],
            'statusOptions' => array_map(static fn (WorkStatus $status): array => ['value' => $status->value, 'label' => $status->label()], WorkStatus::cases()),
            'kinds' => $kinds,
            'splitModes' => GeneralHtmlParser::SPLITS,
            'series' => array_values($seriesGroups),
            'ownSeries' => $ownSeries,
            'collections' => array_values($collectionGroups),
            'ownCollections' => $ownCollections,
            'mayStartCollection' => $this->collections->mayCreate($user),
        ];
    }

    /**
     * Cuts a general file again at another kind of heading, from the text kept with the preview, so the author can see
     * the chapters it would make before anything is written. Only a file read by the general reader can be cut again.
     *
     * @throws ImportException when the upload has expired or the work was not read from a general file
     */
    public function resplit(User $user, string $token, int $index, string $mode): void
    {
        $plan = $this->loadPlan($user, $token);

        if ($plan === null) {
            throw new ImportException('This import has expired. Upload the files again.');
        }

        $files = $plan['sources'][$index] ?? null;

        if (!is_array($files) || $files === [] || !isset($plan['works'][$index])) {
            throw new ImportException('That story was not read from a general file, so it cannot be cut again.');
        }

        $mode = isset(GeneralHtmlParser::SPLITS[$mode]) ? $mode : 'none';
        $group = [];

        foreach ($files as $file) {
            if (is_array($file) && is_string($file['html'] ?? null)) {
                $group[] = ['name' => (string) ($file['name'] ?? ''), 'html' => $file['html'], 'converted' => ($file['converted'] ?? false) === true];
            }
        }

        $plan['works'][$index] = $this->readGeneral($group, $mode)->toArray();
        $plan['splits'][$index] = $mode;

        if (file_put_contents($this->planPath($token), json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX) === false) {
            throw new ImportException('The change could not be kept. Please try again.');
        }
    }

    /**
     * General files that are chapters of one story go together in the order they were given; otherwise each file is a story.
     *
     * @param list<array{name: string, html: string, converted: bool}> $files
     * @return list<list<array{name: string, html: string, converted: bool}>>
     */
    private function groupGeneral(array $files, bool $combine): array
    {
        if ($files === []) {
            return [];
        }

        if (!$combine || count($files) === 1) {
            return array_map(static fn (array $file): array => [$file], $files);
        }

        // Entries of a ZIP all have names, and go in file-name order; files picked one by one keep the order they were chosen in.
        if (count(array_filter($files, static fn (array $file): bool => $file['name'] !== '')) === count($files)) {
            usort($files, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        }

        return [$files];
    }

    /**
     * @param list<array{name: string, html: string, converted: bool}> $group the file, or the files that are chapters of one story
     * @throws ImportException when none of the files holds text
     */
    private function readGeneral(array $group, string $split): OtwWork
    {
        $reader = ($this->general ?? new GeneralHtmlParser())->withSplit($split);
        $parsed = [];
        $left = 0;

        foreach ($group as $file) {
            try {
                $work = $reader->parse($file['html'], $file['name']);
            } catch (ImportException $exception) {
                if (count($group) === 1) {
                    throw $exception;
                }

                $left++;

                continue;
            }

            $parsed[] = $file['converted'] ? $this->withNotes($work, ['This file was not UTF-8 text and was read as Windows-1252 (Western European). Check that accents and quotation marks look right.']) : $work;
        }

        if ($parsed === []) {
            throw new ImportException('None of these files has text to import.');
        }

        if (count($parsed) === 1 && count($group) === 1) {
            return $parsed[0];
        }

        $chapters = [];
        $words = 0;
        $notes = [];
        $hashes = [];

        foreach ($parsed as $work) {
            foreach ($work->chapters as $chapter) {
                // A file that is one untitled chapter is named by its own title, so the contents list reads sensibly.
                $title = $chapter->title !== '' ? $chapter->title : (count($work->chapters) === 1 ? $work->title : '');
                $chapters[] = new OtwChapter(count($chapters) + 1, mb_substr($title, 0, 255, 'UTF-8'), '', '', '', $chapter->html);
            }

            $words += $work->words;
            $notes = array_merge($notes, $work->readerNotes);
            $hashes[] = $work->fileHash;
        }

        $notes[] = count($parsed) . ' files are read as chapters of one story, in the order they were given. Check the title.';

        if ($left > 0) {
            $notes[] = $left . ' file' . ($left === 1 ? ' had' : 's had') . ' no text and ' . ($left === 1 ? 'was' : 'were') . ' left out.';
        }

        $data = $parsed[0]->toArray();
        $data['chapters'] = array_map(static fn (OtwChapter $chapter): array => $chapter->toArray(), $chapters);
        $data['words'] = $words;
        $data['file_hash'] = hash('sha256', implode('|', $hashes));
        $data['reader_notes'] = array_values(array_unique($notes));

        return OtwWork::fromArray($data);
    }

    /**
     * @param list<string> $notes
     */
    private function withNotes(OtwWork $work, array $notes): OtwWork
    {
        $data = $work->toArray();
        $data['reader_notes'] = array_values(array_unique([...$work->readerNotes, ...$notes]));

        return OtwWork::fromArray($data);
    }

    /**
     * What the author typed in the preview for a general file goes over what the reader could not know. A download keeps
     * its own details and is returned as it is. Nothing here picks a term: names go through the same rules as the story
     * form when the story is written, and the chosen rating, warnings and genres are checked there too.
     *
     * @param array<string, mixed> $meta
     * @param array<int|string, mixed> $order the position the author gave each chapter, by its position in the file
     */
    private function applyMeta(OtwWork $work, array $meta, array $order): OtwWork
    {
        if (!$work->isGeneral()) {
            return $work;
        }

        $data = $work->toArray();
        $text = static fn (mixed $value, int $max): string => is_string($value) ? mb_substr(trim(str_replace("\r\n", "\n", $value)), 0, $max, 'UTF-8') : '';
        $names = static function (mixed $value): array {
            $found = [];

            foreach (preg_split('/[,\n]+/', is_string($value) ? $value : '') ?: [] as $name) {
                $name = trim($name);

                if ($name !== '' && count($found) < 50) {
                    $found[mb_strtolower($name, 'UTF-8')] = mb_substr($name, 0, 100, 'UTF-8');
                }
            }

            return array_values($found);
        };

        $title = $text($meta['title'] ?? null, StoryService::TITLE_MAX);
        $data['title'] = $title !== '' ? $title : $work->title;
        $data['summary'] = $text($meta['summary'] ?? null, StoryService::SUMMARY_MAX);
        $data['notes'] = $text($meta['notes'] ?? null, StoryService::NOTES_MAX);
        $data['fandoms'] = $names($meta['fandom'] ?? null);
        $data['characters'] = $names($meta['character'] ?? null);
        $data['relationships'] = $names($meta['relationship'] ?? null);
        $data['tags'] = $names($meta['tag'] ?? null);

        $language = strtolower($text($meta['language'] ?? null, 5));
        $data['language'] = preg_match('/^[a-z]{2,3}\z/', $language) === 1 ? $language : $work->language;

        $published = DateTimeImmutable::createFromFormat('!Y-m-d', $text($meta['published'] ?? null, 10));
        $data['published'] = $published === false ? null : $published->format('Y-m-d');
        $data['updated'] = $data['published'];

        if (count($work->chapters) > 1 && $order !== []) {
            $positions = [];

            foreach ($work->chapters as $position => $chapter) {
                $given = $order[$position] ?? null;
                $positions[$position] = is_string($given) && preg_match('/^[0-9]{1,4}\z/', $given) === 1 ? (int) $given : $position + 1;
            }

            $sorted = array_keys($positions);
            usort($sorted, static fn (int $a, int $b): int => [$positions[$a], $a] <=> [$positions[$b], $b]);
            $data['chapters'] = [];

            foreach ($sorted as $number => $position) {
                $chapter = $work->chapters[$position];
                $data['chapters'][] = (new OtwChapter($number + 1, $chapter->title, $chapter->summary, $chapter->notes, $chapter->endNotes, $chapter->html))->toArray();
            }
        }

        return OtwWork::fromArray($data);
    }

    private function chosenId(int $id, TermType $type): ?int
    {
        return $id > 0 && $this->isVisibleTerm($id, $type) ? $id : null;
    }

    /**
     * @param array<mixed> $ids
     * @return list<int>
     */
    private function chosenIds(array $ids, TermType $type): array
    {
        $found = [];

        foreach ($ids as $id) {
            $checked = is_string($id) && ctype_digit($id) ? $this->chosenId((int) $id, $type) : null;

            if ($checked !== null) {
                $found[$checked] = $checked;
            }
        }

        return array_values($found);
    }

    public function discard(User $user, string $token): void
    {
        if ($this->loadPlan($user, $token) !== null) {
            $this->forget($token);
        }
    }

    /**
     * Starts the import the author confirmed. Nothing is written yet: the run is queued, and step() writes the stories a few at
     * a time, so a long batch stays inside a host's time limit and an interrupted run can be carried on.
     *
     * @param array{include: list<int>, mode: array<int, string>, duplicate: array<int, string>, rating: array<string, int>, warning: array<string, int>, category: array<string, int>, series: array<string, string>, collection: array<string, string>, source_note: list<int>, meta?: array<int, array<string, mixed>>, order?: array<int, array<int, string>>} $choices
     * @return string the token of the run
     * @throws ImportException
     */
    public function apply(User $user, string $token, array $choices, string $ipAddress = ''): string
    {
        $this->requireAllowed($user);
        $plan = $this->loadPlan($user, $token);

        if ($plan === null) {
            throw new ImportException('This import has expired. Upload the files again.');
        }

        $selected = [];

        foreach ($plan['works'] as $index => $data) {
            if (in_array((int) $index, $choices['include'], true)) {
                $selected[(int) $index] = OtwWork::fromArray($data);
            }
        }

        if ($selected === []) {
            throw new ImportException('Tick at least one story to import.');
        }

        // Parts of a series are written in their own order, so the series ends up numbered as the source had it.
        uasort($selected, static fn (OtwWork $a, OtwWork $b): int => [mb_strtolower($a->seriesName, 'UTF-8'), $a->seriesPart] <=> [mb_strtolower($b->seriesName, 'UTF-8'), $b->seriesPart]);

        $job = ['plan' => $token, 'choices' => $choices, 'queue' => array_keys($selected), 'total' => count($selected), 'series_ids' => [], 'collection_ids' => [], 'ip' => $ipAddress];
        $report = ['stories' => [], 'refused' => $plan['refused'], 'series_made' => [], 'collections_made' => [], 'undone' => false];
        $runToken = bin2hex(random_bytes(16));

        $this->database->insertGetId(
            'INSERT INTO {prefix}import_runs (user_id, token, state, report, job, created_at) VALUES (:user, :token, \'running\', :report, :job, :created)',
            ['user' => $user->id, 'token' => $runToken, 'report' => $this->encode($report), 'job' => $this->encode($job), 'created' => date('Y-m-d H:i:s')],
        );

        return $runToken;
    }

    /**
     * Writes the next stories of a run, for about a number of seconds (always at least one), and says how far it has got.
     * Safe to call again after an interruption, and by two requests at once: a lock keeps one at work, and a story whose
     * writing was cut short is removed and made again rather than left half done or written twice.
     *
     * @return array{state: string, done: int, total: int, busy: bool}
     * @throws ImportException when the run is not this person's
     */
    public function step(User $user, string $runToken, ?float $seconds = null): array
    {
        $this->requireAllowed($user);
        $row = $this->runRow($user, $runToken);

        if ($row === null) {
            throw new ImportException('That import is not available.');
        }

        if ($row['state'] !== 'running') {
            return $this->progressOf($row, false);
        }

        $now = date('Y-m-d H:i:s');
        $locked = $this->database->execute(
            'UPDATE {prefix}import_runs SET locked_until = :until WHERE id = :id AND state = \'running\' AND (locked_until IS NULL OR locked_until < :now)',
            ['until' => date('Y-m-d H:i:s', time() + self::LOCK_SECONDS), 'now' => $now, 'id' => (int) $row['id']],
        );

        if ($locked === 0) {
            return $this->progressOf($row, true);
        }

        try {
            $row = $this->runRow($user, $runToken) ?? $row;
            $this->work($user, $row, $seconds ?? $this->stepSeconds);
        } finally {
            $this->database->execute('UPDATE {prefix}import_runs SET locked_until = NULL WHERE id = :id', ['id' => (int) $row['id']]);
        }

        return $this->progressOf($this->runRow($user, $runToken) ?? $row, false);
    }

    /**
     * Stops a run that is still going. What it has written stays, and can still be undone.
     *
     * @throws ImportException
     */
    public function cancel(User $user, string $runToken, string $ipAddress = ''): void
    {
        $row = $this->runRow($user, $runToken);

        if ($row === null) {
            throw new ImportException('That import is not available.');
        }

        if ($row['state'] !== 'running') {
            return;
        }

        $this->database->execute('UPDATE {prefix}import_runs SET state = \'cancelled\', locked_until = NULL WHERE id = :id AND state = \'running\'', ['id' => (int) $row['id']]);
        $job = json_decode((string) ($row['job'] ?? ''), true);

        if (is_array($job) && is_string($job['plan'] ?? null)) {
            $this->forget($job['plan']);
        }

        $this->audit->record($user->id, 'story.import_cancel', 'import', (string) $row['id'], ['stories' => count($this->liveStoryIds((int) $row['id']))], $ipAddress);
    }

    /**
     * Where a run stands, for the progress page.
     *
     * @return array{token: string, state: string, done: int, total: int, busy: bool, lines: list<array<string, mixed>>}|null
     */
    public function progress(User $user, string $runToken): ?array
    {
        $row = $this->runRow($user, $runToken);

        if ($row === null) {
            return null;
        }

        $report = json_decode((string) $row['report'], true);
        $lines = is_array($report) && is_array($report['stories'] ?? null) ? array_values($report['stories']) : [];

        return $this->progressOf($row, false) + ['token' => (string) $row['token'], 'lines' => $lines];
    }

    /**
     * @return array<string, mixed>|null the report and the run's numbers, or null when it is not this person's
     */
    public function report(User $user, string $runToken): ?array
    {
        $row = $this->runRow($user, $runToken);

        if ($row === null) {
            return null;
        }

        $report = json_decode((string) $row['report'], true);
        $report = is_array($report) ? $report : [];
        $stories = is_array($report['stories'] ?? null) ? $report['stories'] : [];

        return [
            'token' => (string) $row['token'],
            'state' => (string) $row['state'],
            'createdAt' => new DateTimeImmutable((string) $row['created_at']),
            'stories' => $stories,
            'refused' => is_array($report['refused'] ?? null) ? $report['refused'] : [],
            'undone' => ($report['undone'] ?? false) === true,
            'imported' => count(array_filter($stories, static fn (mixed $story): bool => is_array($story) && ($story['status'] ?? '') === 'imported')),
            'live' => $this->liveStoryIds((int) $row['id']),
        ];
    }

    /**
     * @return list<array{token: string, state: string, createdAt: DateTimeImmutable, imported: int, undone: bool}>
     */
    public function recentRuns(User $user, int $limit = 10): array
    {
        $runs = [];

        foreach ($this->database->fetchAll('SELECT token, state, report, created_at FROM {prefix}import_runs WHERE user_id = :user ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)), ['user' => $user->id]) as $row) {
            $report = json_decode((string) $row['report'], true);
            $stories = is_array($report) && is_array($report['stories'] ?? null) ? $report['stories'] : [];
            $runs[] = [
                'token' => (string) $row['token'],
                'state' => (string) $row['state'],
                'createdAt' => new DateTimeImmutable((string) $row['created_at']),
                'imported' => count(array_filter($stories, static fn (mixed $story): bool => is_array($story) && ($story['status'] ?? '') === 'imported')),
                'undone' => is_array($report) && ($report['undone'] ?? false) === true,
            ];
        }

        return $runs;
    }

    /**
     * The report as a plain text file's contents.
     *
     * @param array<string, mixed> $report as returned by report()
     */
    public function reportText(array $report): string
    {
        $lines = ['Import of ' . $report['createdAt']->format('Y-m-d H:i'), 'Stories imported: ' . $report['imported'], ''];

        foreach ($report['stories'] as $story) {
            $lines[] = '[' . strtoupper((string) ($story['status'] ?? '')) . '] ' . (string) ($story['title'] ?? '') . ' (' . (string) ($story['file'] ?? '') . ')';

            foreach ((array) ($story['notes'] ?? []) as $note) {
                $lines[] = '    - ' . (string) $note;
            }

            if (($story['reason'] ?? '') !== '') {
                $lines[] = '    ' . (string) $story['reason'];
            }
        }

        foreach ($report['refused'] as $refused) {
            $lines[] = '[REFUSED] ' . (string) ($refused['name'] ?? '') . ': ' . (string) ($refused['error'] ?? '');
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Deletes the stories a run made, and the series and collections it made that are empty afterwards. Terms that were
     * proposed stay, since other stories may use them by now.
     *
     * @return int how many stories were removed
     * @throws ImportException
     */
    public function undo(User $user, string $runToken, string $ipAddress = ''): int
    {
        $row = $this->runRow($user, $runToken);

        if ($row === null) {
            throw new ImportException('That import is not available.');
        }

        if ($row['state'] === 'running') {
            throw new ImportException('That import is still running. Let it finish, or stop it, before undoing it.');
        }

        $report = json_decode((string) $row['report'], true);
        $report = is_array($report) ? $report : [];

        if (($report['undone'] ?? false) === true) {
            throw new ImportException('That import has already been undone.');
        }

        $removed = 0;

        foreach ($this->liveStoryIds((int) $row['id']) as $storyId) {
            try {
                $this->stories->delete($user, $storyId, $ipAddress);
                $removed++;
            } catch (StoryPermissionException) {
                // Already gone or no longer the person's to delete; the rest are still removed.
            }
        }

        foreach ((array) ($report['series_made'] ?? []) as $seriesId) {
            if (is_int($seriesId) && $this->series->members($seriesId) === []) {
                try {
                    $this->series->delete($user, $seriesId, $ipAddress);
                } catch (GroupingException) {
                    continue;
                }
            }
        }

        foreach ((array) ($report['collections_made'] ?? []) as $collectionId) {
            if (is_int($collectionId) && $this->collections->members($collectionId) === []) {
                try {
                    $this->collections->delete($user, $collectionId, $ipAddress);
                } catch (GroupingException) {
                    continue;
                }
            }
        }

        $report['undone'] = true;
        $this->database->execute('UPDATE {prefix}import_runs SET report = :report WHERE id = :id', ['report' => $this->encode($report), 'id' => (int) $row['id']]);
        $this->audit->record($user->id, 'story.import_undo', 'import', (string) $row['id'], ['stories' => $removed], $ipAddress);

        return $removed;
    }

    /**
     * The working part of step(): takes stories off the queue until the time is used, saving after each one.
     *
     * @param array<string, mixed> $row
     */
    private function work(User $user, array $row, float $seconds): void
    {
        $runId = (int) $row['id'];
        $job = json_decode((string) $row['job'], true);
        $report = json_decode((string) $row['report'], true);

        if (!is_array($job) || !is_array($report) || !is_array($job['queue'] ?? null)) {
            $this->database->execute('UPDATE {prefix}import_runs SET state = \'done\' WHERE id = :id', ['id' => $runId]);

            return;
        }

        $plan = is_string($job['plan'] ?? null) ? $this->loadPlan($user, $job['plan'], true) : null;
        $choices = is_array($job['choices'] ?? null) ? $job['choices'] : [];
        $ip = (string) ($job['ip'] ?? '');
        $siteRatings = $this->siteRatings();
        $ownSeries = array_map(static fn ($series): int => $series->id, $this->series->ownedBy($user->id));
        $ownCollections = array_column($this->ownCollections($user), 'id');
        $seriesIds = is_array($job['series_ids'] ?? null) ? $job['series_ids'] : [];
        $collectionIds = is_array($job['collection_ids'] ?? null) ? $job['collection_ids'] : [];
        $started = microtime(true);
        $processed = 0;

        if ($plan !== null) {
            touch($this->planPath((string) $job['plan']));
        }

        while ($job['queue'] !== [] && ($processed === 0 || microtime(true) - $started < $seconds)) {
            $index = (int) array_shift($job['queue']);
            $data = $plan['works'][$index] ?? null;

            if ($data === null) {
                $report['stories'][] = ['title' => '', 'file' => '', 'status' => 'failed', 'reason' => 'The uploaded files were removed before this story could be imported. Upload it again.', 'notes' => []];
            } else {
                $work = $this->applyMeta(OtwWork::fromArray($data), is_array($choices['meta'][$index] ?? null) ? $choices['meta'][$index] : [], is_array($choices['order'][$index] ?? null) ? $choices['order'][$index] : []);
                $this->discardPartial($user, $runId, $work, $ip);
                $duplicate = $this->duplicateOf($user, $work);

                if ($duplicate !== null && ($choices['duplicate'][$index] ?? 'skip') !== 'new') {
                    $report['stories'][] = ['title' => $work->title, 'file' => $work->fileName, 'status' => 'skipped', 'reason' => 'Already imported as "' . $duplicate['title'] . '".', 'notes' => []];
                } else {
                    try {
                        $report['stories'][] = $this->importOne($user, $work, $runId, $index, $choices, $siteRatings, $ownSeries, $ownCollections, $seriesIds, $collectionIds, $report, $ip);
                    } catch (ImportException $exception) {
                        $report['stories'][] = ['title' => $work->title, 'file' => $work->fileName, 'status' => 'failed', 'reason' => $exception->getMessage(), 'notes' => []];
                    }
                }
            }

            $job['series_ids'] = $seriesIds;
            $job['collection_ids'] = $collectionIds;
            $finished = $job['queue'] === [];
            $this->database->execute(
                'UPDATE {prefix}import_runs SET report = :report, job = :job, state = :state WHERE id = :id AND state = \'running\'',
                ['report' => $this->encode($report), 'job' => $this->encode($job), 'state' => $finished ? 'done' : 'running', 'id' => $runId],
            );
            $processed++;
        }

        if ($job['queue'] === []) {
            $imported = count(array_filter($report['stories'], static fn (array $line): bool => ($line['status'] ?? '') === 'imported'));
            $this->audit->record($user->id, 'story.import', 'import', (string) $runId, ['stories' => $imported, 'skipped' => count($report['stories']) - $imported], $ip);

            if (is_string($job['plan'] ?? null)) {
                $this->forget($job['plan']);
            }
        }
    }

    /**
     * A story this run began for the work but did not finish (the request was cut short) is removed before the work is written
     * again, so an interruption never leaves a half-made story or a second copy.
     */
    private function discardPartial(User $user, int $runId, OtwWork $work, string $ipAddress): void
    {
        foreach ($this->database->fetchAll('SELECT story_id FROM {prefix}story_imports WHERE run_id = :run AND source_hash = :hash', ['run' => $runId, 'hash' => $work->fingerprint()]) as $found) {
            $this->discardStory($user, (int) $found['story_id'], $ipAddress);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{state: string, done: int, total: int, busy: bool}
     */
    private function progressOf(array $row, bool $busy): array
    {
        $report = json_decode((string) $row['report'], true);
        $job = json_decode((string) ($row['job'] ?? ''), true);
        $done = is_array($report) && is_array($report['stories'] ?? null) ? count($report['stories']) : 0;
        $left = is_array($job) && is_array($job['queue'] ?? null) ? count($job['queue']) : 0;

        return ['state' => (string) $row['state'], 'done' => $done, 'total' => $done + $left, 'busy' => $busy];
    }

    /**
     * Brings one work in: the story, its chapters, its classification, then (when asked) publication, dates, series and collections.
     *
     * @param array<string, mixed> $choices
     * @param array<int, string> $siteRatings
     * @param list<int> $ownSeries
     * @param list<int> $ownCollections
     * @param array<string, int> $seriesIds
     * @param array<string, int> $collectionIds
     * @param array<string, mixed> $report
     * @return array<string, mixed> the line for the report
     * @throws ImportException when the story could not be made; nothing of it is left
     */
    private function importOne(User $user, OtwWork $work, int $runId, int $index, array $choices, array $siteRatings, array $ownSeries, array $ownCollections, array &$seriesIds, array &$collectionIds, array &$report, string $ipAddress): array
    {
        $notes = [];
        $classified = $this->classification($work);
        $notes = array_merge($notes, $classified['notes'], $this->textNotes($work));
        $general = $work->isGeneral();
        // What the author chose in the preview for a general file; a download carries its own details instead.
        $meta = $general && is_array($choices['meta'][$index] ?? null) ? $choices['meta'][$index] : [];

        $title = mb_substr($work->title, 0, StoryService::TITLE_MAX, 'UTF-8');
        $storyNotes = $work->notes;

        if (in_array($index, $choices['source_note'], true) && $work->sourceUrl !== '') {
            $storyNotes = trim($storyNotes . "\n\n" . self::SOURCE_NOTE . $work->sourceUrl);
        }

        try {
            $story = $this->stories->create($user, [
                'title' => $title,
                'summary' => $this->cut($work->summary, StoryService::SUMMARY_MAX),
                'notes' => $this->cut($storyNotes, StoryService::NOTES_MAX),
                'end_notes' => $this->cut($work->endNotes, StoryService::END_NOTES_MAX),
                'language' => $work->language !== '' ? $work->language : 'en',
                'work_status' => $general
                    ? (WorkStatus::tryFrom((string) ($meta['status'] ?? '')) ?? WorkStatus::Ongoing)->value
                    : ($work->complete ? WorkStatus::Completed : WorkStatus::Ongoing)->value,
            ], $ipAddress);
        } catch (StoryValidationException $exception) {
            throw new ImportException('The story details were refused: ' . implode(' ', $exception->errors));
        } catch (StoryPermissionException $exception) {
            throw new ImportException($exception->getMessage());
        }

        // The link is written first, so a story whose writing is cut short can be found and removed when the run is carried on.
        $this->database->execute(
            'INSERT INTO {prefix}story_imports (run_id, user_id, story_id, source_id, source_url, source_hash, created_at) VALUES (:run, :user, :story, :source, :url, :hash, :created)',
            ['run' => $runId, 'user' => $user->id, 'story' => $story->id, 'source' => $work->sourceId, 'url' => $work->sourceUrl, 'hash' => $work->fingerprint(), 'created' => date('Y-m-d H:i:s')],
        );

        $chapterIds = [];

        try {
            $removed = 0;

            foreach ($work->chapters as $position => $chapter) {
                $chapterIds[] = $this->addChapter($user, $story->id, $chapter, count($work->chapters) === 1, $removed, $ipAddress);
            }

            if ($removed > 0) {
                $notes[] = $removed . ' script, style or embedded item' . ($removed === 1 ? ' was' : 's were') . ' removed from the text.';
            }

            $selection = $classified['selection'];
            $ratingId = $general ? $this->chosenId((int) ($meta['rating'] ?? 0), TermType::Rating) : $this->mappedId($choices['rating'] ?? [], $work->rating, TermType::Rating);

            if ($ratingId !== null) {
                $selection[TermType::Rating->value] = [$ratingId];
            } else {
                $notes[] = $general || $work->rating === '' ? 'No rating was chosen.' : 'No rating was chosen for "' . $work->rating . '".';
            }

            if ($general) {
                $selection[TermType::Warning->value] = $this->chosenIds((array) ($meta['warning'] ?? []), TermType::Warning);

                if ($this->taxonomy->isEnabled(TermType::Genre)) {
                    $selection[TermType::Genre->value] = $this->chosenIds((array) ($meta['genre'] ?? []), TermType::Genre);
                }
            } else {
                $selection[TermType::Warning->value] = $this->mappedIds($choices['warning'] ?? [], $work->warnings, TermType::Warning);

                if ($this->taxonomy->isEnabled(TermType::RelationshipCategory)) {
                    $selection[TermType::RelationshipCategory->value] = $this->mappedIds($choices['category'] ?? [], $work->categories, TermType::RelationshipCategory);
                }
            }

            $this->stories->classify($user, $story->id, $selection, $ipAddress);
        } catch (StoryValidationException $exception) {
            $this->discardStory($user, $story->id, $ipAddress);

            throw new ImportException('The story could not be saved: ' . implode(' ', $exception->errors));
        } catch (Throwable $exception) {
            $this->discardStory($user, $story->id, $ipAddress);

            throw new ImportException('The story could not be saved, so nothing of it was kept.');
        }

        $state = PublicationState::Draft;

        if (($choices['mode'][$index] ?? 'draft') === 'submit') {
            if ($ratingId === null) {
                $notes[] = 'Left as a draft because it has no rating yet.';
            } else {
                try {
                    foreach ($chapterIds as $chapterId) {
                        $this->chapters->publish($user, $chapterId, $ipAddress);
                    }

                    $state = $this->stories->publish($user, $story->id, $ipAddress)->state;
                } catch (StoryValidationException $exception) {
                    $this->withdrawChapters($user, $chapterIds, $ipAddress);
                    $notes[] = 'Left as a draft: ' . implode(' ', $exception->errors);
                } catch (Throwable) {
                    $this->withdrawChapters($user, $chapterIds, $ipAddress);
                    $notes[] = 'Left as a draft because it could not be submitted.';
                }
            }
        }

        $this->stories->applyDates($user, $story->id, $work->publishedAt, $work->updatedAt, $work->publishedAt);

        foreach ($chapterIds as $chapterId) {
            $this->chapters->applyDates($user, $chapterId, $work->publishedAt, $work->updatedAt, $work->publishedAt);
        }

        if ($work->publishedAt === null) {
            $notes[] = $general ? 'No publication date was given, so the dates are those of the import.' : 'The file has no publication date, so the dates are those of the import.';
        }

        $this->groupings($user, $work, $story->id, $choices, $ownSeries, $ownCollections, $seriesIds, $collectionIds, $report, $notes, $ipAddress);
        $this->hooks?->doAction('story_imported', $story->id, $user->id, $work->sourceId);

        return ['title' => $title, 'file' => $work->fileName, 'status' => 'imported', 'story_id' => $story->id, 'state' => $state->value, 'chapters' => count($chapterIds), 'notes' => $notes];
    }

    /**
     * @param array<string, mixed> $choices
     * @param list<int> $ownSeries
     * @param list<int> $ownCollections
     * @param array<string, int> $seriesIds
     * @param array<string, int> $collectionIds
     * @param array<string, mixed> $report
     * @param list<string> $notes
     */
    private function groupings(User $user, OtwWork $work, int $storyId, array $choices, array $ownSeries, array $ownCollections, array &$seriesIds, array &$collectionIds, array &$report, array &$notes, string $ipAddress): void
    {
        try {
            if ($work->seriesName !== '' && GroupingSettings::seriesEnabled($this->config)) {
                $key = $this->key($work->seriesName);
                $choice = (string) ($choices['series'][$key] ?? 'none');

                if ($choice === 'new') {
                    if (!isset($seriesIds[$key])) {
                        $seriesIds[$key] = $this->series->create($user, ['title' => $work->seriesName, 'summary' => '', 'work_status' => WorkStatus::Ongoing->value], $ipAddress)->id;
                        $report['series_made'][] = $seriesIds[$key];
                    }

                    $this->series->assign($user, $storyId, $seriesIds[$key], null, $ipAddress);
                } elseif (ctype_digit($choice) && in_array((int) $choice, $ownSeries, true)) {
                    $this->series->assign($user, $storyId, (int) $choice, null, $ipAddress);
                } else {
                    $notes[] = 'It is part ' . max(1, $work->seriesPart) . ' of "' . $work->seriesName . '" at the source; it was imported on its own.';
                }
            }

            if (GroupingSettings::collectionsEnabled($this->config)) {
                foreach ($work->collections as $name) {
                    $key = $this->key($name);
                    $choice = (string) ($choices['collection'][$key] ?? 'none');

                    if ($choice === 'new' && $this->collections->mayCreate($user)) {
                        if (!isset($collectionIds[$key])) {
                            $collectionIds[$key] = $this->collections->create($user, ['title' => $name, 'description' => '', 'visibility' => 'public'], $ipAddress)->id;
                            $report['collections_made'][] = $collectionIds[$key];
                        }

                        $this->collections->submit($user, $collectionIds[$key], $storyId, '', $ipAddress);
                    } elseif (ctype_digit($choice) && in_array((int) $choice, $ownCollections, true)) {
                        $this->collections->submit($user, (int) $choice, $storyId, '', $ipAddress);
                    }
                }
            }
        } catch (GroupingException $exception) {
            $notes[] = 'Series or collection: ' . $exception->getMessage();
        }
    }

    private function addChapter(User $user, int $storyId, OtwChapter $chapter, bool $single, int &$removed, string $ipAddress): int
    {
        $removed += preg_match_all('~<\s*(?:script|style|iframe|object|embed|form)\b|\sstyle\s*=|\son[a-z]+\s*=~i', $chapter->html);
        $clean = $this->sanitizer->clean($chapter->html);

        try {
            return $this->chapters->add($user, $storyId, [
                'title' => $single ? '' : mb_substr($chapter->title, 0, ChapterService::TITLE_MAX, 'UTF-8'),
                'content' => $clean,
                'format' => ContentFormat::Html->value,
                'notes' => $this->cut($chapter->notes, ChapterService::NOTES_MAX),
                'end_notes' => $this->cut($chapter->endNotes, ChapterService::END_NOTES_MAX),
            ], $ipAddress)->id;
        } catch (StoryValidationException $exception) {
            throw new ImportException('Chapter ' . $chapter->number . ' was refused: ' . implode(' ', $exception->errors));
        }
    }

    /**
     * @param list<int> $chapterIds
     */
    private function withdrawChapters(User $user, array $chapterIds, string $ipAddress): void
    {
        foreach ($chapterIds as $chapterId) {
            try {
                $this->chapters->unpublish($user, $chapterId, $ipAddress);
            } catch (Throwable) {
                continue;
            }
        }
    }

    private function discardStory(User $user, int $storyId, string $ipAddress): void
    {
        try {
            $this->stories->delete($user, $storyId, $ipAddress);
        } catch (Throwable) {
            // Nothing more can be done here; the story stays a private draft the author can delete from the list.
        }
    }

    /**
     * What each kind of term would do for this work: matched to a term that exists, made as a proposal, or dropped
     * with the reason. The same answer is used to write the story, so the preview never promises more than is done.
     *
     * @return array{selection: array<string, list<string>>, terms: array<string, list<array{name: string, status: string, note: string}>>, notes: list<string>}
     */
    private function classification(OtwWork $work): array
    {
        $selection = [];
        $terms = [];
        $notes = [];
        $parent = 0;

        foreach ([[TermType::Fandom, $work->fandoms], [TermType::Character, $work->characters], [TermType::Relationship, $work->relationships], [TermType::Tag, $work->tags]] as [$type, $names]) {
            if ($names === []) {
                continue;
            }

            if (!$this->taxonomy->isEnabled($type)) {
                $terms[$type->value] = array_map(static fn (string $name): array => ['name' => $name, 'status' => 'dropped', 'note' => 'this site does not use ' . $type->pluralLabel()], $names);

                continue;
            }

            $kept = [];
            $rows = [];
            $seen = [];
            $matched = null;

            foreach ($names as $name) {
                try {
                    $clean = $this->taxonomy->cleanName($name);
                } catch (TaxonomyException $exception) {
                    $rows[] = ['name' => $name, 'status' => 'dropped', 'note' => $exception->getMessage()];

                    continue;
                }

                if (isset($seen[mb_strtolower($clean, 'UTF-8')])) {
                    continue;
                }

                $seen[mb_strtolower($clean, 'UTF-8')] = true;
                $found = $this->taxonomy->resolve($type, $clean, $type->belongsToFandom() ? $parent : 0) ?? ($type->belongsToFandom() && $parent !== 0 ? $this->taxonomy->resolve($type, $clean, 0) : null);

                if (count($kept) >= $type->maximumPerStory()) {
                    $rows[] = ['name' => $clean, 'status' => 'dropped', 'note' => 'a story can carry at most ' . $type->maximumPerStory() . ' ' . mb_strtolower($type->pluralLabel(), 'UTF-8')];
                } elseif ($found !== null && $found->hidden) {
                    $rows[] = ['name' => $clean, 'status' => 'dropped', 'note' => 'no longer offered on this site'];
                } elseif ($found !== null) {
                    $rows[] = ['name' => $clean, 'status' => 'matches', 'note' => $found->name !== $clean ? 'listed here as ' . $found->name : ''];
                    $kept[] = $clean;
                    $matched = $found;
                } elseif ($this->taxonomy->writersMayPropose($type)) {
                    $rows[] = ['name' => $clean, 'status' => 'new', 'note' => 'added as a proposal for an administrator to review'];
                    $kept[] = $clean;
                    $matched = null;
                } else {
                    $rows[] = ['name' => $clean, 'status' => 'dropped', 'note' => 'not on this site\'s list, and writers cannot add to it'];
                }
            }

            if ($type === TermType::Fandom) {
                $parent = count($kept) === 1 && $matched instanceof Term ? $matched->id : 0;
            }

            $selection[$type->value] = $kept;
            $terms[$type->value] = $rows;
        }

        return ['selection' => $selection, 'terms' => $terms, 'notes' => $notes];
    }

    /**
     * @return list<string>
     */
    private function textNotes(OtwWork $work): array
    {
        $notes = $work->readerNotes;

        if (mb_strlen($work->title, 'UTF-8') > StoryService::TITLE_MAX) {
            $notes[] = 'The title is longer than this site allows and will be shortened.';
        }

        foreach ([['summary', $work->summary, StoryService::SUMMARY_MAX], ['notes', $work->notes, StoryService::NOTES_MAX], ['end notes', $work->endNotes, StoryService::END_NOTES_MAX]] as [$label, $text, $max]) {
            if (mb_strlen($text, 'UTF-8') > $max) {
                $notes[] = 'The ' . $label . ' are longer than this site allows and will be shortened.';
            }
        }

        foreach ($work->chapters as $chapter) {
            if (mb_strlen($chapter->html, 'UTF-8') > ChapterService::CONTENT_MAX) {
                $notes[] = 'Chapter ' . $chapter->number . ' is too long for this site to store in one piece, so this story cannot be imported.';
            }
        }

        return $notes;
    }

    /**
     * @param array<string, int> $map posted choices keyed by the value's key
     */
    private function mappedId(array $map, string $source, TermType $type): ?int
    {
        if ($source === '') {
            return null;
        }

        $id = (int) ($map[$this->key($source)] ?? 0);

        return $id > 0 && $this->isVisibleTerm($id, $type) ? $id : null;
    }

    /**
     * @param array<string, int> $map
     * @param list<string> $sources
     * @return list<int>
     */
    private function mappedIds(array $map, array $sources, TermType $type): array
    {
        $ids = [];

        foreach ($sources as $source) {
            $id = $this->mappedId($map, $source, $type);

            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function isVisibleTerm(int $id, TermType $type): bool
    {
        $term = $this->taxonomy->find($id);

        return $term !== null && $term->type === $type && !$term->hidden;
    }

    private function resolvesTo(TermType $type, string $name): ?int
    {
        try {
            $term = $this->taxonomy->resolve($type, $this->taxonomy->cleanName($name));
        } catch (TaxonomyException) {
            return null;
        }

        return $term !== null && !$term->hidden ? $term->id : null;
    }

    /**
     * @param array<int, string> $siteRatings
     */
    private function suggestRating(string $source, array $siteRatings): ?int
    {
        return $this->resolvesTo(TermType::Rating, $source) ?? RatingScale::suggest($source, $siteRatings);
    }

    /**
     * @return array<int, string> rating id => name, in the site's order
     */
    private function siteRatings(): array
    {
        $ratings = [];

        foreach ($this->taxonomy->list(TermType::Rating, ['visible' => true], 200) as $term) {
            $ratings[$term->id] = $term->name;
        }

        return $ratings;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function options(TermType $type): array
    {
        return array_map(static fn (Term $term): array => ['id' => $term->id, 'name' => $term->name], $this->taxonomy->list($type, ['visible' => true], 200));
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    private function ownCollections(User $user): array
    {
        $own = [];

        foreach ($this->collections->listFor($user) as $row) {
            if ($row['collection']->ownerId === $user->id) {
                $own[] = ['id' => $row['collection']->id, 'title' => $row['collection']->title];
            }
        }

        return $own;
    }

    /**
     * @param list<array{id: int, title: string}> $own
     */
    private function matchByTitle(array $own, string $name): ?string
    {
        foreach ($own as $item) {
            if (mb_strtolower($item['title'], 'UTF-8') === mb_strtolower($name, 'UTF-8')) {
                return (string) $item['id'];
            }
        }

        return null;
    }

    /**
     * @return array{id: int, title: string}|null this person's own earlier import of the same work
     */
    private function duplicateOf(User $user, OtwWork $work): ?array
    {
        $row = $this->database->fetchOne(
            'SELECT s.id, s.title FROM {prefix}story_imports si JOIN {prefix}stories s ON s.id = si.story_id
             WHERE si.user_id = :user AND ((si.source_id <> \'\' AND si.source_id = :source) OR si.source_hash = :hash) ORDER BY si.id DESC LIMIT 1',
            ['user' => $user->id, 'source' => $work->sourceId, 'hash' => $work->fingerprint()],
        );

        return $row === null ? null : ['id' => (int) $row['id'], 'title' => (string) $row['title']];
    }

    /**
     * The names the person writes under: their display name and any they typed, so the byline can be compared. Only a
     * warning is ever made from a mismatch, since pseuds and anonymous works are legitimate.
     *
     * @return list<string>
     */
    private function ownNames(User $user, string $typed): array
    {
        $names = [mb_strtolower($user->displayName, 'UTF-8')];

        foreach (explode(',', $typed) as $name) {
            $name = mb_strtolower(trim($name), 'UTF-8');

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param list<string> $own
     */
    private function bylineMatches(string $byline, array $own): bool
    {
        foreach (explode(',', $byline) as $part) {
            $part = trim($part);
            $inner = preg_match('/^(.*?)\s*\((.*)\)\z/u', $part, $match) === 1 ? [trim($match[1]), trim($match[2])] : [$part];

            foreach ($inner as $name) {
                if (in_array(mb_strtolower($name, 'UTF-8'), $own, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function runRow(User $user, string $runToken): ?array
    {
        if (preg_match('/^[a-f0-9]{32}\z/', $runToken) !== 1) {
            return null;
        }

        return $this->database->fetchOne('SELECT * FROM {prefix}import_runs WHERE token = :token AND user_id = :user', ['token' => $runToken, 'user' => $user->id]);
    }

    /**
     * @return list<int> the stories of this run that still exist
     */
    private function liveStoryIds(int $runId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['story_id'],
            $this->database->fetchAll('SELECT story_id FROM {prefix}story_imports WHERE run_id = :run ORDER BY id', ['run' => $runId]),
        );
    }

    private function requireAllowed(User $user): void
    {
        if (!$this->allowed($user)) {
            throw new ImportException('Importing stories is not open to this account.');
        }
    }

    private function key(string $value): string
    {
        return 'k' . substr(md5(mb_strtolower(trim($value), 'UTF-8')), 0, 12);
    }

    private function cut(string $text, int $max): string
    {
        return mb_strlen($text, 'UTF-8') > $max ? rtrim(mb_substr($text, 0, $max - 1, 'UTF-8')) . '…' : $text;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function encode(array $report): string
    {
        return (string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private function planPath(string $token): string
    {
        return rtrim($this->workDirectory, '/') . '/' . $token . '.json';
    }

    private function forget(string $token): void
    {
        if (is_file($this->planPath($token))) {
            unlink($this->planPath($token));
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->workDirectory) && !mkdir($this->workDirectory, 0750, true) && !is_dir($this->workDirectory)) {
            throw new ImportException('The upload could not be kept for the preview. Please try again.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadPlan(User $user, string $token, bool $running = false): ?array
    {
        if (preg_match('/^[a-f0-9]{32}\z/', $token) !== 1 || !is_file($this->planPath($token))) {
            return null;
        }

        $plan = json_decode((string) file_get_contents($this->planPath($token)), true);

        if (!is_array($plan) || ($plan['user'] ?? 0) !== $user->id || (!$running && (int) ($plan['created'] ?? 0) < time() - self::PLAN_TTL) || !is_array($plan['works'] ?? null)) {
            return null;
        }

        $plan['refused'] = is_array($plan['refused'] ?? null) ? $plan['refused'] : [];
        $plan['pseuds'] = (string) ($plan['pseuds'] ?? '');

        return $plan;
    }

    /**
     * Removes kept uploads nobody confirmed, so a forgotten preview does not stay on the server.
     */
    private function sweep(): void
    {
        foreach (glob(rtrim($this->workDirectory, '/') . '/*.json') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - self::PLAN_TTL) {
                unlink($file);
            }
        }
    }
}
