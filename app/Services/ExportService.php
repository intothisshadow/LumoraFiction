<?php

/**
 * Exports stories as a portable ZIP: a manifest and one file per story, with optional cover pictures.
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

use FilesystemIterator;
use JsonException;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Media\CoverImageStore;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\Chapter;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\User;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Who may ask is decided here, not by a screen: a story's owner or staff who may manage it export one story, an author or an
 * Administrator exports that author's stories, and only an Administrator exports the archive. A story is exported in any
 * state, because the file is the writer's own work (or the Administrator's backup of it), and the file is never kept: it is
 * built for one request and removed once it has been sent. People are named by display name and public slug only. No login
 * name, email address, password hash or token is ever read for an export. The format is described in docs/EXPORT-FORMAT.md,
 * and FORMAT_VERSION is raised whenever a field is renamed or removed.
 */
final class ExportService
{
    public const FORMAT = 'lumora-fiction-export';

    public const STORY_FORMAT = 'lumora-fiction-story';

    public const FORMAT_VERSION = 1;

    /** Stories read, and held in memory, at a time. Each is written to disk at once, so a large archive stays small in memory. */
    private const BATCH = 100;

    /** An export that was never collected (a crashed request) is cleared away after this long, in seconds. */
    private const STALE_AFTER = 3600;

    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly Database $database,
        private readonly Configuration $config,
        private readonly AuditLogService $audit,
        private readonly StoryService $stories,
        private readonly ChapterService $chapters,
        private readonly TaxonomyService $taxonomy,
        private readonly UserService $users,
        private readonly SeriesService $series,
        private readonly CollectionService $collections,
        private readonly ?CoverImageStore $covers,
        private readonly string $directory,
        private readonly string $appVersion,
    ) {
    }

    public static function available(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * @return array{path: string, name: string, stories: int}
     * @throws ExportException
     */
    public function story(User $actor, int $storyId, bool $covers = false, string $ipAddress = ''): array
    {
        $story = $this->stories->find($storyId);

        if ($story === null || $story->isTrashed() || !$this->stories->canManage($actor, $story)) {
            throw new ExportException('That story is not available.');
        }

        return $this->build('story', 'id = :id', ['id' => $story->id], $covers, $actor, (string) $story->id, $story->slug, $ipAddress);
    }

    /**
     * @return array{path: string, name: string, stories: int}
     * @throws ExportException
     */
    public function author(User $actor, int $authorId, bool $covers = false, string $ipAddress = ''): array
    {
        if (!$actor->can(Capability::WriteStories) || ($actor->id !== $authorId && !$actor->can(Capability::ManageSite))) {
            throw new ExportException('You may only export your own stories.');
        }

        return $this->build('author', 'owner_id = :owner', ['owner' => $authorId], $covers, $actor, (string) $authorId, 'author-' . $authorId, $ipAddress);
    }

    /**
     * @return array{path: string, name: string, stories: int}
     * @throws ExportException
     */
    public function archive(User $actor, bool $covers = false, string $ipAddress = ''): array
    {
        if (!$actor->can(Capability::ManageSite)) {
            throw new ExportException('Only an Administrator can export the whole archive.');
        }

        return $this->build('archive', '1 = 1', [], $covers, $actor, 'archive', 'archive', $ipAddress);
    }

    /**
     * The people who have stories, for the Administrator's choice of whose to export.
     *
     * @return list<array{id: int, name: string, stories: int}>
     */
    public function authors(): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['display_name'], 'stories' => (int) $row['total']],
            $this->database->fetchAll(
                'SELECT u.id, u.display_name, COUNT(*) AS total FROM {prefix}stories s JOIN {prefix}users u ON u.id = s.owner_id WHERE s.trashed_at IS NULL
                 GROUP BY u.id, u.display_name ORDER BY u.display_name ASC, u.id ASC LIMIT 1000',
            ),
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array{path: string, name: string, stories: int}
     * @throws ExportException
     */
    private function build(string $scope, string $condition, array $params, bool $covers, User $actor, string $target, string $label, string $ipAddress): array
    {
        if (!self::available()) {
            throw new ExportException('This server cannot make ZIP files, so nothing can be exported.');
        }

        $this->clearStale();

        $work = $this->directory . '/export-' . bin2hex(random_bytes(8));
        $zipPath = $work . '.zip';

        if (!is_dir($work . '/stories') && !mkdir($work . '/stories', 0775, true) && !is_dir($work . '/stories')) {
            throw new ExportException('The export could not be started. Check that the storage folder is writable.');
        }

        try {
            $counts = $this->writeFiles($work, $condition, $params, $covers, $scope);

            if ($counts['stories'] === 0) {
                throw new ExportException('There are no stories to export.');
            }

            $this->zip($work, $zipPath, $counts['covers']);
        } catch (JsonException) {
            $this->removeTree($work);

            throw new ExportException('Some text could not be written to the export.');
        } catch (ExportException $exception) {
            $this->removeTree($work);

            if (is_file($zipPath)) {
                unlink($zipPath);
            }

            throw $exception;
        }

        $this->removeTree($work);
        $this->audit->record($actor->id, 'export.' . $scope, 'export', $target, ['stories' => $counts['stories'], 'chapters' => $counts['chapters'], 'covers' => $covers], $ipAddress);

        return ['path' => $zipPath, 'name' => 'LumoraFiction-export-' . $label . '-' . gmdate('Y-m-d') . '.zip', 'stories' => $counts['stories']];
    }

    /**
     * Writes one JSON file per story and the manifest into the work folder.
     *
     * @param array<string, mixed> $params
     * @return array{stories: int, chapters: int, covers: array<string, string>} covers maps the name inside the ZIP to the file on disk
     */
    private function writeFiles(string $work, string $condition, array $params, bool $covers, string $scope): array
    {
        $index = [];
        $authors = [];
        $seriesDefinitions = [];
        $collectionDefinitions = [];
        $coverFiles = [];
        $chapterTotal = 0;
        $last = 0;

        while (true) {
            $rows = $this->database->fetchAll(
                'SELECT * FROM {prefix}stories WHERE trashed_at IS NULL AND (' . $condition . ') AND id > :last ORDER BY id ASC LIMIT ' . self::BATCH,
                $params + ['last' => $last],
            );

            if ($rows === []) {
                break;
            }

            $batch = $this->stories->fromRows($rows);
            $ids = array_map(static fn (Story $story): int => $story->id, $batch);
            $last = max($ids);

            $profiles = $this->users->publicProfiles(array_map(static fn (Story $story): int => $story->ownerId, $batch));
            $terms = $this->taxonomy->termsForStories($ids);
            $chapters = $this->chapters->forExport($ids);
            $seriesOf = $this->series->membershipFor($ids);
            $collectionsOf = $this->collections->membershipFor($ids);
            $parents = $this->taxonomy->namesFor($this->parentIds($terms));

            // Everyone a membership names is resolved in one query, so a person is only ever named by their public details.
            $others = [];

            foreach ($seriesOf as $membership) {
                $others[] = $membership['series']->ownerId;
            }

            foreach ($collectionsOf as $list) {
                foreach ($list as $membership) {
                    $others[] = $membership['collection']->ownerId;
                    $others[] = $membership['addedBy'];
                }
            }

            $people = $profiles + $this->users->publicProfiles(array_values(array_diff($others, array_keys($profiles))));

            foreach ($batch as $story) {
                $author = $this->person($people, $story->ownerId);
                $authors[$author['slug']] = $author;
                $document = $this->document($story, $author, $terms[$story->id] ?? [], $parents, $chapters[$story->id] ?? [], $seriesOf[$story->id] ?? null, $collectionsOf[$story->id] ?? [], $people);
                $name = $story->id . '-' . $story->slug . '.json';

                if (str_contains($story->slug, '/') || str_contains($story->slug, '..')) {
                    $name = $story->id . '.json';
                }

                if ($covers && $story->hasCover() && $this->covers !== null && $this->covers->exists($story->coverFile)) {
                    $inside = 'covers/' . $story->id . '-' . basename($story->coverFile);
                    $coverFiles[$inside] = $this->covers->filePath($story->coverFile);
                    $document['cover']['file'] = $inside;
                }

                file_put_contents($work . '/stories/' . $name, json_encode($document, self::JSON_FLAGS) . "\n");

                $chapterTotal += count($chapters[$story->id] ?? []);
                $index[] = ['id' => $story->id, 'slug' => $story->slug, 'title' => $story->title, 'author' => $author['slug'], 'file' => 'stories/' . $name, 'chapters' => count($chapters[$story->id] ?? []), 'words' => $story->wordCount, 'publication_state' => $story->state->value];

                if (isset($seriesOf[$story->id])) {
                    $membership = $seriesOf[$story->id];
                    $owner = $this->person($people, $membership['series']->ownerId);
                    $seriesDefinitions[$membership['series']->id] ??= [
                        'slug' => $membership['series']->slug,
                        'title' => $membership['series']->title,
                        'summary' => $membership['series']->summary,
                        'work_status' => $membership['series']->workStatus->value,
                        'author' => $owner['slug'],
                        'stories' => [],
                    ];
                    $seriesDefinitions[$membership['series']->id]['stories'][] = [$membership['position'], $story->slug];
                }

                foreach ($collectionsOf[$story->id] ?? [] as $membership) {
                    $curator = $this->person($people, $membership['collection']->ownerId);
                    $collectionDefinitions[$membership['collection']->id] ??= [
                        'slug' => $membership['collection']->slug,
                        'title' => $membership['collection']->title,
                        'description' => $membership['collection']->description,
                        'visibility' => $membership['collection']->isPublic ? 'public' : 'private',
                        'curator' => $curator['slug'],
                        'stories' => [],
                    ];
                    $collectionDefinitions[$membership['collection']->id]['stories'][] = ['story' => $story->slug, 'state' => $membership['state'], 'added_by' => $this->person($people, $membership['addedBy'])['slug']];
                }
            }
        }

        $series = [];

        foreach ($seriesDefinitions as $definition) {
            usort($definition['stories'], static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $definition['stories'] = array_map(static fn (array $entry): string => $entry[1], $definition['stories']);
            $series[] = $definition;
        }

        $manifest = [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'exported_at' => gmdate('c'),
            'app_version' => $this->appVersion,
            'scope' => $scope,
            'site_name' => (string) $this->config->setting('site.name', 'Lumora Fiction'),
            'counts' => ['stories' => count($index), 'chapters' => $chapterTotal, 'series' => count($series), 'collections' => count($collectionDefinitions), 'covers' => count($coverFiles)],
            'authors' => array_values($authors),
            'stories' => $index,
            'series' => $series,
            'collections' => array_values($collectionDefinitions),
        ];

        file_put_contents($work . '/manifest.json', json_encode($manifest, self::JSON_FLAGS) . "\n");

        return ['stories' => count($index), 'chapters' => $chapterTotal, 'covers' => $coverFiles];
    }

    /**
     * @param array{display_name: string, slug: string} $author
     * @param list<Term>|array<string, list<Term>> $terms
     * @param array<int, string> $parents
     * @param list<Chapter> $chapters
     * @param array{series: \LumoraFiction\Models\Series, position: int}|null $series
     * @param list<array{collection: \LumoraFiction\Models\Collection, state: string, addedBy: int, note: string}> $collections
     * @param array<int, array{display_name: string, slug: string}> $people
     * @return array<string, mixed>
     */
    private function document(Story $story, array $author, array $terms, array $parents, array $chapters, ?array $series, array $collections, array $people): array
    {
        $grouped = [];

        foreach (TermType::cases() as $type) {
            foreach ($terms[$type->value] ?? [] as $term) {
                $entry = ['name' => $term->name, 'slug' => $term->slug];

                // A character or a relationship may belong to a fandom, and the same name in two fandoms is two terms.
                if ($term->parentId > 0 && isset($parents[$term->parentId])) {
                    $entry['fandom'] = $parents[$term->parentId];
                }

                $grouped[$type->value][] = $entry;
            }
        }

        return [
            'format' => self::STORY_FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'id' => $story->id,
            'slug' => $story->slug,
            'title' => $story->title,
            'author' => $author,
            'summary' => $story->summary,
            'notes' => $story->notes,
            'end_notes' => $story->endNotes,
            'disclaimer' => $story->disclaimer,
            'language' => $story->language,
            'work_status' => $story->workStatus->value,
            'publication_state' => $story->state->value,
            'word_count' => $story->wordCount,
            'created_at' => $story->createdAt->format('c'),
            'updated_at' => $story->updatedAt->format('c'),
            'published_at' => $story->publishedAt?->format('c'),
            'cover' => $story->hasCover() ? ['file' => null, 'alt' => $story->coverAlt] : null,
            'terms' => $grouped,
            'series' => $series === null ? null : ['slug' => $series['series']->slug, 'title' => $series['series']->title, 'position' => $series['position']],
            'collections' => array_map(
                fn (array $membership): array => [
                    'slug' => $membership['collection']->slug,
                    'title' => $membership['collection']->title,
                    'state' => $membership['state'],
                    'added_by' => $this->person($people, $membership['addedBy'])['slug'],
                    'note' => $membership['note'],
                ],
                $collections,
            ),
            'chapters' => array_map(
                static fn (Chapter $chapter): array => [
                    'number' => $chapter->number,
                    'title' => $chapter->title,
                    'format' => $chapter->format->value,
                    'content' => $chapter->content,
                    'notes' => $chapter->notes,
                    'end_notes' => $chapter->endNotes,
                    'word_count' => $chapter->wordCount,
                    'publication_state' => $chapter->state->value,
                    'created_at' => $chapter->createdAt->format('c'),
                    'updated_at' => $chapter->updatedAt->format('c'),
                    'published_at' => $chapter->publishedAt?->format('c'),
                ],
                $chapters,
            ),
        ];
    }

    /**
     * @param array<int, array{display_name: string, slug: string}> $people
     * @return array{display_name: string, slug: string}
     */
    private function person(array $people, int $id): array
    {
        return $people[$id] ?? ['display_name' => '', 'slug' => ''];
    }

    /**
     * @param array<int, array<string, list<Term>>> $terms
     * @return list<int>
     */
    private function parentIds(array $terms): array
    {
        $ids = [];

        foreach ($terms as $byType) {
            foreach ($byType as $list) {
                foreach ($list as $term) {
                    if ($term->parentId > 0) {
                        $ids[] = $term->parentId;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, string> $covers name inside the ZIP => file on disk
     * @throws ExportException
     */
    private function zip(string $work, string $zipPath, array $covers): void
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ExportException('The export file could not be created. Check that the storage folder is writable.');
        }

        $zip->addFile($work . '/manifest.json', 'manifest.json');

        foreach (new FilesystemIterator($work . '/stories') as $file) {
            $zip->addFile($file->getPathname(), 'stories/' . $file->getFilename());
        }

        foreach ($covers as $inside => $path) {
            if (is_file($path)) {
                $zip->addFile($path, $inside);
            }
        }

        if (!$zip->close()) {
            throw new ExportException('The export file could not be written. The disk may be full.');
        }
    }

    /**
     * Removes exports a crashed request left behind, so private stories never sit on the server.
     */
    private function clearStale(): void
    {
        if (!is_dir($this->directory)) {
            if (!mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
                throw new ExportException('The export could not be started. Check that the storage folder is writable.');
            }

            return;
        }

        foreach (new FilesystemIterator($this->directory) as $entry) {
            if (!str_starts_with($entry->getFilename(), 'export-') || $entry->getMTime() > time() - self::STALE_AFTER) {
                continue;
            }

            if ($entry->isDir()) {
                $this->removeTree($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
