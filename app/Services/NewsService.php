<?php

/**
 * News posts: staff announcements shown to readers, kept apart from stories.
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
use LumoraFiction\Core\Admin\ColumnSort;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Helpers\Slug;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\ContentFormat;
use LumoraFiction\Models\NewsPost;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\User;

/**
 * News belongs to the site, not to whoever wrote it, so anyone who holds PublishNews may change any post. Every
 * change is audited. A post is only public while it is published; a draft is never returned by a public lookup.
 * Text is stored as written and sanitised by the content renderer whenever it is shown.
 */
final class NewsService
{
    public const TITLE_MAX = 200;

    public const CONTENT_MAX = 200000;

    /** The slug column holds 120 characters: room for a ten-digit id, the hyphen and the title words. */
    private const SLUG_WORDS_MAX = 100;

    /** Posts per page of the public archive and of the admin list. */
    public const PAGE_SIZE = 10;

    public const STAFF_PAGE_SIZE = 25;

    public function __construct(
        private readonly Database $database,
        private readonly AuditLogService $audit,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    /**
     * The new post is a draft.
     *
     * @param array<string, mixed> $input title, content, format, pinned
     * @throws NewsPermissionException
     * @throws NewsValidationException
     */
    public function create(User $actor, array $input, string $ipAddress = ''): NewsPost
    {
        $this->requireStaff($actor);
        $values = $this->validated($input, null);

        $id = (int) $this->database->transaction(function () use ($actor, $values, $ipAddress): int {
            $now = date('Y-m-d H:i:s');
            // A placeholder slug is replaced at once; the real one needs the id the insert just produced.
            $id = (int) $this->database->insertGetId(
                'INSERT INTO {prefix}news_posts (author_id, title, slug, content, content_format, publication_state, pinned, published_at, created_at, updated_at)
                 VALUES (:author, :title, :slug, :content, :format, :state, :pinned, NULL, :created, :updated)',
                [
                    'author' => $actor->id,
                    'title' => $values['title'],
                    'slug' => 'new-' . bin2hex(random_bytes(8)),
                    'content' => $values['content'],
                    'format' => $values['format']->value,
                    'state' => PublicationState::Draft->value,
                    'pinned' => $values['pinned'] ? 1 : 0,
                    'created' => $now,
                    'updated' => $now,
                ],
            );
            $this->database->execute('UPDATE {prefix}news_posts SET slug = :slug WHERE id = :id', ['slug' => $this->slugFor($id, $values['title']), 'id' => $id]);
            $this->audit->record($actor->id, 'news.create', 'news_post', (string) $id, [], $ipAddress);

            return $id;
        });

        return $this->mustFind($id);
    }

    /**
     * Only the fields present in $input change. The slug follows the title until the first publication and never changes after.
     *
     * @param array<string, mixed> $input
     * @throws NewsPermissionException
     * @throws NewsValidationException
     */
    public function update(User $actor, int $id, array $input, string $ipAddress = ''): NewsPost
    {
        $post = $this->manageable($actor, $id);
        $values = $this->validated($input, $post);

        $this->database->transaction(function () use ($actor, $post, $values, $ipAddress): void {
            $this->database->execute(
                'UPDATE {prefix}news_posts SET title = :title, slug = :slug, content = :content, content_format = :format, pinned = :pinned, updated_at = :updated WHERE id = :id',
                [
                    'title' => $values['title'],
                    'slug' => $post->publishedAt === null ? $this->slugFor($post->id, $values['title']) : $post->slug,
                    'content' => $values['content'],
                    'format' => $values['format']->value,
                    'pinned' => $values['pinned'] ? 1 : 0,
                    'updated' => date('Y-m-d H:i:s'),
                    'id' => $post->id,
                ],
            );
            $this->audit->record($actor->id, 'news.update', 'news_post', (string) $post->id, [], $ipAddress);
        });

        return $this->mustFind($post->id);
    }

    /**
     * @throws NewsPermissionException
     */
    public function publish(User $actor, int $id, string $ipAddress = ''): NewsPost
    {
        $post = $this->manageable($actor, $id);

        if ($post->isPublished()) {
            return $post;
        }

        $published = $this->transition($post, PublicationState::Published, $actor, 'news.publish', $ipAddress);
        $this->hooks?->doAction('news_published', $published, $actor);

        return $published;
    }

    /**
     * The post goes back to a draft. It keeps its address and its first publication date for when it comes back.
     *
     * @throws NewsPermissionException
     */
    public function unpublish(User $actor, int $id, string $ipAddress = ''): NewsPost
    {
        $post = $this->manageable($actor, $id);

        return $post->isPublished() ? $this->transition($post, PublicationState::Draft, $actor, 'news.unpublish', $ipAddress) : $post;
    }

    /**
     * @throws NewsPermissionException
     */
    public function delete(User $actor, int $id, string $ipAddress = ''): void
    {
        $post = $this->manageable($actor, $id);

        $this->database->transaction(function () use ($actor, $post, $ipAddress): void {
            $this->database->execute('DELETE FROM {prefix}news_posts WHERE id = :id', ['id' => $post->id]);
            $this->audit->record($actor->id, 'news.delete', 'news_post', (string) $post->id, ['state' => $post->state->value], $ipAddress);
        });

        $this->hooks?->doAction('news_deleted', $post->id);
    }

    /**
     * Any post in any state, for the admin screens only. A public page must use findPublicBySlug().
     */
    public function find(int $id): ?NewsPost
    {
        $row = $this->database->fetchOne('SELECT * FROM {prefix}news_posts WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @throws NewsPermissionException when the person may not manage news or the post does not exist
     */
    public function manageable(User $actor, int $id): NewsPost
    {
        $this->requireStaff($actor);

        return $this->find($id) ?? throw new NewsPermissionException('That news post is not available.');
    }

    public function findPublicBySlug(string $slug): ?NewsPost
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM {prefix}news_posts WHERE slug = :slug AND publication_state = :state',
            ['slug' => $slug, 'state' => PublicationState::Published->value],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Pinned posts first, then the newest. This is the one order every public list uses.
     *
     * @return list<NewsPost>
     */
    public function listPublic(int $limit = self::PAGE_SIZE, int $offset = 0): array
    {
        return array_map($this->hydrate(...), $this->database->fetchAll(
            'SELECT * FROM {prefix}news_posts WHERE publication_state = :state ORDER BY pinned DESC, published_at DESC, id DESC LIMIT '
            . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            ['state' => PublicationState::Published->value],
        ));
    }

    public function countPublic(): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM {prefix}news_posts WHERE publication_state = :state',
            ['state' => PublicationState::Published->value],
        );
    }

    /**
     * The sortable columns of the admin list. The most recently changed first stays the default.
     */
    public static function sortFrom(?string $sort, ?string $direction): ColumnSort
    {
        return ColumnSort::resolve([
            'title' => ['sql' => 'n.title', 'first' => ColumnSort::ASCENDING],
            'status' => ['sql' => 'n.publication_state', 'first' => ColumnSort::ASCENDING],
            'published' => ['sql' => 'n.published_at', 'first' => ColumnSort::DESCENDING],
            'updated' => ['sql' => 'n.updated_at', 'first' => ColumnSort::DESCENDING],
        ], 'updated', ColumnSort::DESCENDING, 'n.id', $sort, $direction);
    }

    /**
     * @param array{state?: string, search?: string} $filters
     * @return list<NewsPost>
     */
    public function listForStaff(User $actor, array $filters, int $limit = self::STAFF_PAGE_SIZE, int $offset = 0, ?ColumnSort $sort = null): array
    {
        if (!$actor->can(Capability::PublishNews)) {
            return [];
        }

        [$where, $params] = $this->staffFilter($filters);

        return array_map($this->hydrate(...), $this->database->fetchAll(
            'SELECT n.* FROM {prefix}news_posts n WHERE ' . $where . ' ORDER BY ' . ($sort ?? self::sortFrom(null, null))->orderBy()
            . ' LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            $params,
        ));
    }

    /**
     * @param array{state?: string, search?: string} $filters
     */
    public function countForStaff(User $actor, array $filters): int
    {
        if (!$actor->can(Capability::PublishNews)) {
            return 0;
        }

        [$where, $params] = $this->staffFilter($filters);

        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}news_posts n WHERE ' . $where, $params);
    }

    /**
     * @return array{draft: int, published: int}
     */
    public function countsByState(): array
    {
        $counts = ['draft' => 0, 'published' => 0];

        foreach ($this->database->fetchAll('SELECT publication_state, COUNT(*) AS total FROM {prefix}news_posts GROUP BY publication_state') as $row) {
            if (isset($counts[$row['publication_state']])) {
                $counts[$row['publication_state']] = (int) $row['total'];
            }
        }

        return $counts;
    }

    /**
     * @param array{state?: string, search?: string} $filters
     * @return array{0: string, 1: array<string, string>}
     */
    private function staffFilter(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];
        $state = PublicationState::tryFrom($filters['state'] ?? '');

        if ($state === PublicationState::Draft || $state === PublicationState::Published) {
            $where[] = 'n.publication_state = :state';
            $params['state'] = $state->value;
        }

        $search = mb_substr(trim($filters['search'] ?? ''), 0, 100, 'UTF-8');

        if ($search !== '') {
            $where[] = 'n.title LIKE :search';
            $params['search'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    private function requireStaff(User $actor): void
    {
        if (!$actor->can(Capability::PublishNews)) {
            throw new NewsPermissionException('That news post is not available.');
        }
    }

    private function slugFor(int $id, string $title): string
    {
        return $id . '-' . Slug::fromText($title, self::SLUG_WORDS_MAX, 'news');
    }

    private function transition(NewsPost $post, PublicationState $to, User $actor, string $auditAction, string $ipAddress): NewsPost
    {
        $this->database->transaction(function () use ($post, $to, $actor, $auditAction, $ipAddress): void {
            $now = date('Y-m-d H:i:s');

            $this->database->execute(
                'UPDATE {prefix}news_posts SET publication_state = :state, slug = :slug, published_at = :published, updated_at = :updated WHERE id = :id',
                [
                    'state' => $to->value,
                    // The address is fixed by the first publication; until then it still follows the title.
                    'slug' => $post->publishedAt === null && $to === PublicationState::Published ? $this->slugFor($post->id, $post->title) : $post->slug,
                    'published' => $to === PublicationState::Published && $post->publishedAt === null ? $now : $post->publishedAt?->format('Y-m-d H:i:s'),
                    'updated' => $now,
                    'id' => $post->id,
                ],
            );
            $this->audit->record($actor->id, $auditAction, 'news_post', (string) $post->id, ['from' => $post->state->value, 'to' => $to->value], $ipAddress);
        });

        return $this->mustFind($post->id);
    }

    private function mustFind(int $id): NewsPost
    {
        return $this->find($id) ?? throw new NewsPermissionException('That news post is not available.');
    }

    /**
     * @param array<string, mixed> $input
     * @return array{title: string, content: string, format: ContentFormat, pinned: bool}
     */
    private function validated(array $input, ?NewsPost $existing): array
    {
        $errors = [];

        $title = array_key_exists('title', $input) ? $input['title'] : ($existing->title ?? '');
        $title = is_string($title) ? trim($title) : null;

        if ($title === null || !mb_check_encoding($title, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $title) === 1) {
            $errors['title'] = 'This contains characters that are not allowed.';
            $title = '';
        } elseif ($title === '') {
            $errors['title'] = 'Give the post a title.';
        } elseif (mb_strlen($title, 'UTF-8') > self::TITLE_MAX) {
            $errors['title'] = 'Keep the title to ' . self::TITLE_MAX . ' characters or fewer.';
        }

        $content = array_key_exists('content', $input) ? $input['content'] : ($existing->content ?? '');

        if (!is_string($content) || !mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            $errors['content'] = 'The text contains characters that are not allowed.';
            $content = '';
        } elseif (mb_strlen($content, 'UTF-8') > self::CONTENT_MAX) {
            $errors['content'] = 'This post is too long to save. Keep it under ' . number_format(self::CONTENT_MAX) . ' characters.';
        }

        $formatInput = array_key_exists('format', $input) ? $input['format'] : ($existing->format ?? ContentFormat::Html)->value;
        $format = is_string($formatInput) ? ContentFormat::tryFrom($formatInput) : null;

        if ($format === null) {
            $errors['format'] = 'Choose how the text is written.';
        }

        $pinned = array_key_exists('pinned', $input) ? $input['pinned'] === true : ($existing->pinned ?? false);

        if ($errors !== []) {
            throw new NewsValidationException($errors);
        }

        return ['title' => $title, 'content' => $content, 'format' => $format ?? ContentFormat::Html, 'pinned' => $pinned];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): NewsPost
    {
        return new NewsPost(
            id: (int) $row['id'],
            authorId: (int) $row['author_id'],
            title: (string) $row['title'],
            slug: (string) $row['slug'],
            content: (string) $row['content'],
            format: ContentFormat::tryFrom((string) $row['content_format']) ?? ContentFormat::Plain,
            // An unrecognised state counts as a draft so a corrupt value fails closed rather than going public.
            state: PublicationState::tryFrom((string) $row['publication_state']) === PublicationState::Published ? PublicationState::Published : PublicationState::Draft,
            pinned: (int) $row['pinned'] === 1,
            publishedAt: is_string($row['published_at']) && $row['published_at'] !== '' ? new DateTimeImmutable($row['published_at']) : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
