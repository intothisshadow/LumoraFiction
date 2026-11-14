<?php

/**
 * Creates, edits, publishes and finds static pages such as About, FAQ and the privacy policy.
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

use Closure;
use DateTimeImmutable;
use LumoraFiction\Core\Admin\BulkResult;
use LumoraFiction\Core\Admin\ColumnSort;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Helpers\Slug;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\ContentFormat;
use LumoraFiction\Models\Page;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\User;
use PDOException;

/**
 * Pages belong to the site, so anyone who may manage the site may change any page, and every change is audited. A page
 * is only public while it is published; a draft is never returned by a public lookup. Text is stored as written and
 * sanitised by the content renderer whenever it is shown, exactly as chapter text is.
 */
final class PageService
{
    public const TITLE_MAX = 200;

    public const SLUG_MAX = 100;

    public const DESCRIPTION_MAX = 300;

    public const CONTENT_MAX = 200000;

    public const STAFF_PAGE_SIZE = 25;

    /**
     * First path segments the application owns, folders the web server serves, and files it answers itself. A page
     * called one of these would never be reached, or would be mistaken for the real thing.
     */
    private const RESERVED = [
        'admin', 'account', 'author', 'authors', 'browse', 'chapter', 'story', 'stories', 'search', 'news',
        'fandom', 'character', 'relationship', 'genre', 'tag', 'category', 'rating', 'warning', 'relationship-category',
        'install', 'content', 'assets', 'storage', 'config', 'app', 'docs', 'api', 'feed', 'feeds', 'login', 'logout', 'register',
        'index', 'new', 'rewrite-check', 'sitemap', 'robots', 'theme', 'theme-options',
    ];

    /**
     * Starter drafts offered when no page exists yet. The words are neutral placeholders for the administrator to replace.
     * They are never published automatically.
     *
     * @var array<string, array{title: string, slug: string, text: string}>
     */
    public const STARTERS = [
        'about' => [
            'title' => 'About',
            'slug' => 'about',
            'text' => "Tell readers what this archive is for, who runs it and what kind of stories they will find here.\n\nReplace this text with your own before publishing the page.",
        ],
        'faq' => [
            'title' => 'FAQ',
            'slug' => 'faq',
            'text' => "Answer the questions readers and writers ask most often: how to register, how to post a story, how tags and ratings work.\n\nReplace this text with your own before publishing the page.",
        ],
        'terms' => [
            'title' => 'Terms of Service',
            'slug' => 'terms-of-service',
            'text' => "State the rules for using this site: what may be posted, what happens to content that breaks the rules and how accounts can be closed.\n\nThis is a placeholder, not legal advice. Replace it with your own terms before publishing the page.",
        ],
        'privacy' => [
            'title' => 'Privacy Policy',
            'slug' => 'privacy-policy',
            'text' => "Explain what personal information this site keeps (an email address, a display name, reading history), why it is kept, who can see it, how long it is kept, and how someone can ask for a copy or for their account to be deleted.\n\nThis is a placeholder, not legal advice. Replace it with your own policy before publishing the page.",
        ],
    ];

    /** @var list<Page>|null the published pages, loaded once because the menu and the footer both ask for them */
    private ?array $published = null;

    /**
     * @param (Closure(string): bool)|null $addressTaken answers whether another part of the application already serves "/{slug}";
     *                                                   a closure so routes that plugins add later are counted too
     */
    public function __construct(
        private readonly Database $database,
        private readonly AuditLogService $audit,
        private readonly ?Closure $addressTaken = null,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    /**
     * The new page is a draft.
     *
     * @param array<string, mixed> $input title, slug, content, format, in_menu, in_footer, sort_order, description, noindex
     * @throws PagePermissionException
     * @throws PageValidationException
     */
    public function create(User $actor, array $input, string $ipAddress = ''): Page
    {
        $this->requireStaff($actor);
        $values = $this->validated($input, null);

        try {
            $id = (int) $this->database->transaction(function () use ($actor, $values, $ipAddress): int {
                $now = date('Y-m-d H:i:s');
                $id = (int) $this->database->insertGetId(
                    'INSERT INTO {prefix}pages (title, slug, content, content_format, publication_state, in_menu, in_footer, sort_order, description, noindex, edited_by, published_at, created_at, updated_at)
                     VALUES (:title, :slug, :content, :format, :state, :in_menu, :in_footer, :sort_order, :description, :noindex, :editor, NULL, :created, :updated)',
                    [
                        'title' => $values['title'],
                        'slug' => $values['slug'],
                        'content' => $values['content'],
                        'format' => $values['format']->value,
                        'state' => PublicationState::Draft->value,
                        'in_menu' => $values['in_menu'] ? 1 : 0,
                        'in_footer' => $values['in_footer'] ? 1 : 0,
                        'sort_order' => $values['sort_order'],
                        'description' => $values['description'],
                        'noindex' => $values['noindex'] ? 1 : 0,
                        'editor' => $actor->id,
                        'created' => $now,
                        'updated' => $now,
                    ],
                );
                $this->audit->record($actor->id, 'page.create', 'page', (string) $id, ['slug' => $values['slug']], $ipAddress);

                return $id;
            });
        } catch (PDOException $exception) {
            throw $this->duplicateOrRethrow($exception);
        }

        $this->published = null;

        return $this->mustFind($id);
    }

    /**
     * Only the fields present in $input change.
     *
     * @param array<string, mixed> $input
     * @throws PagePermissionException
     * @throws PageValidationException
     */
    public function update(User $actor, int $id, array $input, string $ipAddress = ''): Page
    {
        $page = $this->manageable($actor, $id);
        $values = $this->validated($input, $page);

        try {
            $this->database->transaction(function () use ($actor, $page, $values, $ipAddress): void {
                $this->database->execute(
                    'UPDATE {prefix}pages SET title = :title, slug = :slug, content = :content, content_format = :format, in_menu = :in_menu, in_footer = :in_footer,
                     sort_order = :sort_order, description = :description, noindex = :noindex, edited_by = :editor, updated_at = :updated WHERE id = :id',
                    [
                        'title' => $values['title'],
                        'slug' => $values['slug'],
                        'content' => $values['content'],
                        'format' => $values['format']->value,
                        'in_menu' => $values['in_menu'] ? 1 : 0,
                        'in_footer' => $values['in_footer'] ? 1 : 0,
                        'sort_order' => $values['sort_order'],
                        'description' => $values['description'],
                        'noindex' => $values['noindex'] ? 1 : 0,
                        'editor' => $actor->id,
                        'updated' => date('Y-m-d H:i:s'),
                        'id' => $page->id,
                    ],
                );
                $this->audit->record($actor->id, 'page.update', 'page', (string) $page->id, $page->slug === $values['slug'] ? [] : ['slug' => ['from' => $page->slug, 'to' => $values['slug']]], $ipAddress);
            });
        } catch (PDOException $exception) {
            throw $this->duplicateOrRethrow($exception);
        }

        $this->published = null;

        if ($page->slug !== $values['slug']) {
            $this->hooks?->doAction('page_slug_changed', $page->slug, $values['slug']);
        }

        return $this->mustFind($page->id);
    }

    /**
     * @throws PagePermissionException
     */
    public function publish(User $actor, int $id, string $ipAddress = ''): Page
    {
        $page = $this->manageable($actor, $id);

        return $page->isPublished() ? $page : $this->transition($page, PublicationState::Published, $actor, 'page.publish', $ipAddress);
    }

    /**
     * @throws PagePermissionException
     */
    public function unpublish(User $actor, int $id, string $ipAddress = ''): Page
    {
        $page = $this->manageable($actor, $id);

        return $page->isPublished() ? $this->transition($page, PublicationState::Draft, $actor, 'page.unpublish', $ipAddress) : $page;
    }

    /**
     * A deleted page leaves every menu and the footer with it, because both are built from the table.
     *
     * @throws PagePermissionException
     */
    public function delete(User $actor, int $id, string $ipAddress = ''): void
    {
        $page = $this->manageable($actor, $id);

        $this->database->transaction(function () use ($actor, $page, $ipAddress): void {
            $this->database->execute('DELETE FROM {prefix}pages WHERE id = :id', ['id' => $page->id]);
            $this->audit->record($actor->id, 'page.delete', 'page', (string) $page->id, ['slug' => $page->slug, 'state' => $page->state->value], $ipAddress);
        });

        $this->published = null;
        $this->hooks?->doAction('page_deleted', $page->id);
    }

    /** The actions bulk() accepts. */
    public const BULK_ACTIONS = ['publish', 'unpublish', 'menu', 'footer', 'remove', 'delete'];

    /**
     * Applies one action to several pages, one at a time through the same methods as the single actions, so each page is
     * checked and audited on its own and the address and redirect rules of a single change apply (placement changes
     * leave the address alone).
     *
     * @param list<int> $ids at most BulkResult::LIMIT are handled
     * @throws PagePermissionException when the person may not manage pages
     * @throws PageValidationException when the action is not one of BULK_ACTIONS
     */
    public function bulk(User $actor, string $action, array $ids, string $ipAddress = ''): BulkResult
    {
        $this->requireStaff($actor);

        if (!in_array($action, self::BULK_ACTIONS, true)) {
            throw new PageValidationException(['bulk_action' => 'Choose what to do with the selected pages.']);
        }

        $result = new BulkResult();

        foreach (array_slice(array_values(array_unique($ids)), 0, BulkResult::LIMIT) as $id) {
            try {
                match ($action) {
                    'publish' => $this->publish($actor, $id, $ipAddress),
                    'unpublish' => $this->unpublish($actor, $id, $ipAddress),
                    'menu' => $this->update($actor, $id, ['in_menu' => true], $ipAddress),
                    'footer' => $this->update($actor, $id, ['in_footer' => true], $ipAddress),
                    'remove' => $this->update($actor, $id, ['in_menu' => false, 'in_footer' => false], $ipAddress),
                    'delete' => $this->delete($actor, $id, $ipAddress),
                };
                $result->done();
            } catch (PagePermissionException) {
                $result->skip('Some of the pages could not be found.');
            } catch (PageValidationException) {
                $result->skip('Some of the pages could not be changed.');
            }
        }

        if ($result->doneCount() > 0) {
            $this->audit->record($actor->id, 'page.bulk', 'page', '', ['action' => $action, 'done' => $result->doneCount(), 'skipped' => $result->skippedCount()], $ipAddress);
        }

        return $result;
    }

    /**
     * Adds one starter draft. A starter whose address is already in use is refused rather than renamed.
     *
     * @throws PagePermissionException
     * @throws PageValidationException
     */
    public function createStarter(User $actor, string $key, string $ipAddress = ''): Page
    {
        $starter = self::STARTERS[$key] ?? throw new PageValidationException(['starter' => 'That starter page does not exist.']);

        return $this->create($actor, [
            'title' => $starter['title'],
            'slug' => $starter['slug'],
            'content' => $starter['text'],
            'format' => ContentFormat::Plain->value,
        ], $ipAddress);
    }

    /**
     * Any page in any state, for the admin screens only. A public page must use findPublicBySlug().
     */
    public function find(int $id): ?Page
    {
        $row = $this->database->fetchOne('SELECT * FROM {prefix}pages WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @throws PagePermissionException when the person may not manage pages or the page does not exist
     */
    public function manageable(User $actor, int $id): Page
    {
        $this->requireStaff($actor);

        return $this->find($id) ?? throw new PagePermissionException('That page is not available.');
    }

    public function findPublicBySlug(string $slug): ?Page
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return null;
        }

        try {
            $row = $this->database->fetchOne(
                'SELECT * FROM {prefix}pages WHERE slug = :slug AND publication_state = :state',
                ['slug' => $slug, 'state' => PublicationState::Published->value],
            );
        } catch (PDOException $exception) {
            // Any address nothing else owns lands here, so a table that is not made yet must read as "no such page", not as a crash.
            if (($exception->errorInfo[1] ?? null) === 1146) {
                return null;
            }

            throw $exception;
        }

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * The published pages in menu order. Both the main menu and the footer list are cut from this one query.
     *
     * @return list<Page>
     */
    public function published(): array
    {
        if ($this->published !== null) {
            return $this->published;
        }

        try {
            return $this->published = array_map($this->hydrate(...), $this->database->fetchAll(
                'SELECT * FROM {prefix}pages WHERE publication_state = :state ORDER BY sort_order, title, id',
                ['state' => PublicationState::Published->value],
            ));
        } catch (PDOException $exception) {
            // Every public page asks for the menu. Between uploading the files and the administrator's first visit,
            // when the table is not made yet, the site must keep working without the page links.
            if (($exception->errorInfo[1] ?? null) === 1146) {
                return $this->published = [];
            }

            throw $exception;
        }
    }

    /**
     * @return list<Page>
     */
    public function menuPages(): array
    {
        return array_values(array_filter($this->published(), static fn (Page $page): bool => $page->inMenu));
    }

    /**
     * @return list<Page>
     */
    public function footerPages(): array
    {
        return array_values(array_filter($this->published(), static fn (Page $page): bool => $page->inFooter));
    }

    /**
     * The sortable columns of the admin list. The most recently changed first stays the default.
     */
    public static function sortFrom(?string $sort, ?string $direction): ColumnSort
    {
        return ColumnSort::resolve([
            'title' => ['sql' => 'p.title', 'first' => ColumnSort::ASCENDING],
            'status' => ['sql' => 'p.publication_state', 'first' => ColumnSort::ASCENDING],
            'order' => ['sql' => 'p.sort_order', 'first' => ColumnSort::ASCENDING],
            'updated' => ['sql' => 'p.updated_at', 'first' => ColumnSort::DESCENDING],
        ], 'updated', ColumnSort::DESCENDING, 'p.id', $sort, $direction);
    }

    /**
     * @param array{state?: string, search?: string} $filters
     * @return list<Page>
     */
    public function listForStaff(User $actor, array $filters, int $limit = self::STAFF_PAGE_SIZE, int $offset = 0, ?ColumnSort $sort = null): array
    {
        if (!$actor->can(Capability::ManageSite)) {
            return [];
        }

        [$where, $params] = $this->staffFilter($filters);

        return array_map($this->hydrate(...), $this->database->fetchAll(
            'SELECT p.* FROM {prefix}pages p WHERE ' . $where . ' ORDER BY ' . ($sort ?? self::sortFrom(null, null))->orderBy()
            . ' LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            $params,
        ));
    }

    /**
     * @param array{state?: string, search?: string} $filters
     */
    public function countForStaff(User $actor, array $filters): int
    {
        if (!$actor->can(Capability::ManageSite)) {
            return 0;
        }

        [$where, $params] = $this->staffFilter($filters);

        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}pages p WHERE ' . $where, $params);
    }

    /**
     * The starter drafts whose address no page uses yet, so the offer shrinks as the administrator adds them.
     *
     * @return array<string, array{title: string, slug: string, text: string}>
     */
    public function availableStarters(): array
    {
        $used = array_column($this->database->fetchAll('SELECT slug FROM {prefix}pages'), 'slug');

        return array_filter(self::STARTERS, static fn (array $starter): bool => !in_array($starter['slug'], $used, true));
    }

    public function countAll(): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}pages');
    }

    /**
     * Every page as a choice for a setting that names one, such as the privacy policy: id => title, published pages first.
     *
     * @return array<int, string>
     */
    public function choices(): array
    {
        $choices = [];

        foreach ($this->database->fetchAll('SELECT id, title FROM {prefix}pages WHERE publication_state = :state ORDER BY title, id', ['state' => PublicationState::Published->value]) as $row) {
            $choices[(int) $row['id']] = (string) $row['title'];
        }

        return $choices;
    }

    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*\z/';

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
            $where[] = 'p.publication_state = :state';
            $params['state'] = $state->value;
        }

        $search = mb_substr(trim($filters['search'] ?? ''), 0, 100, 'UTF-8');

        if ($search !== '') {
            $where[] = 'p.title LIKE :search';
            $params['search'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    private function requireStaff(User $actor): void
    {
        if (!$actor->can(Capability::ManageSite)) {
            throw new PagePermissionException('That page is not available.');
        }
    }

    private function transition(Page $page, PublicationState $to, User $actor, string $auditAction, string $ipAddress): Page
    {
        $this->database->transaction(function () use ($page, $to, $actor, $auditAction, $ipAddress): void {
            $now = date('Y-m-d H:i:s');

            $this->database->execute(
                'UPDATE {prefix}pages SET publication_state = :state, published_at = :published, edited_by = :editor, updated_at = :updated WHERE id = :id',
                [
                    'state' => $to->value,
                    'published' => $to === PublicationState::Published && $page->publishedAt === null ? $now : $page->publishedAt?->format('Y-m-d H:i:s'),
                    'editor' => $actor->id,
                    'updated' => $now,
                    'id' => $page->id,
                ],
            );
            $this->audit->record($actor->id, $auditAction, 'page', (string) $page->id, ['from' => $page->state->value, 'to' => $to->value], $ipAddress);
        });

        $this->published = null;

        return $this->mustFind($page->id);
    }

    private function mustFind(int $id): Page
    {
        return $this->find($id) ?? throw new PagePermissionException('That page is not available.');
    }

    /**
     * Two administrators saving the same address at once get the key's refusal, which is answered like the early check.
     */
    private function duplicateOrRethrow(PDOException $exception): PDOException|PageValidationException
    {
        return ($exception->errorInfo[1] ?? null) === 1062
            ? new PageValidationException(['slug' => 'Another page already uses that address.'])
            : $exception;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{title: string, slug: string, content: string, format: ContentFormat, in_menu: bool, in_footer: bool, sort_order: int, description: string, noindex: bool}
     */
    private function validated(array $input, ?Page $existing): array
    {
        $errors = [];

        $title = array_key_exists('title', $input) ? $input['title'] : ($existing->title ?? '');
        $title = is_string($title) ? trim($title) : null;

        if ($title === null || !mb_check_encoding($title, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $title) === 1) {
            $errors['title'] = 'This contains characters that are not allowed.';
            $title = '';
        } elseif ($title === '') {
            $errors['title'] = 'Give the page a title.';
        } elseif (mb_strlen($title, 'UTF-8') > self::TITLE_MAX) {
            $errors['title'] = 'Keep the title to ' . self::TITLE_MAX . ' characters or fewer.';
        }

        $slug = array_key_exists('slug', $input) ? $input['slug'] : ($existing->slug ?? '');
        $slug = is_string($slug) ? strtolower(trim($slug)) : null;

        // An empty address follows the title, so the form can leave the field blank and still get a sensible one.
        if ($slug === '' && $title !== '' && !isset($errors['title'])) {
            $slug = Slug::fromText($title, self::SLUG_MAX, 'page');
        }

        if (!isset($errors['title']) || $slug !== '') {
            $slugProblem = $this->slugProblem($slug, $existing);

            if ($slugProblem !== null) {
                $errors['slug'] = $slugProblem;
            }
        }

        $content = array_key_exists('content', $input) ? $input['content'] : ($existing->content ?? '');

        if (!is_string($content) || !mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            $errors['content'] = 'The text contains characters that are not allowed.';
            $content = '';
        } elseif (mb_strlen($content, 'UTF-8') > self::CONTENT_MAX) {
            $errors['content'] = 'This page is too long to save. Keep it under ' . number_format(self::CONTENT_MAX) . ' characters.';
        }

        $formatInput = array_key_exists('format', $input) ? $input['format'] : ($existing->format ?? ContentFormat::Html)->value;
        $format = is_string($formatInput) ? ContentFormat::tryFrom($formatInput) : null;

        if ($format === null) {
            $errors['format'] = 'Choose how the text is written.';
        }

        $description = array_key_exists('description', $input) ? $input['description'] : ($existing->description ?? '');
        $description = is_string($description) ? trim((string) preg_replace('/\s+/u', ' ', $description)) : null;

        if ($description === null || !mb_check_encoding($description, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $description) === 1) {
            $errors['description'] = 'This contains characters that are not allowed.';
            $description = '';
        } elseif (mb_strlen($description, 'UTF-8') > self::DESCRIPTION_MAX) {
            $errors['description'] = 'Keep the description to ' . self::DESCRIPTION_MAX . ' characters or fewer.';
        }

        $order = array_key_exists('sort_order', $input) ? $input['sort_order'] : ($existing->sortOrder ?? 0);
        $order = is_int($order) ? $order : (is_string($order) && preg_match('/^[0-9]{1,6}\z/', trim($order)) === 1 ? (int) trim($order) : null);

        if ($order === null) {
            $errors['sort_order'] = 'Enter a whole number from 0 to 999999.';
            $order = 0;
        }

        if ($errors !== []) {
            throw new PageValidationException($errors);
        }

        return [
            'title' => $title,
            'slug' => (string) $slug,
            'content' => $content,
            'format' => $format ?? ContentFormat::Html,
            'in_menu' => array_key_exists('in_menu', $input) ? $input['in_menu'] === true : ($existing->inMenu ?? false),
            'in_footer' => array_key_exists('in_footer', $input) ? $input['in_footer'] === true : ($existing->inFooter ?? false),
            'sort_order' => $order,
            'description' => $description,
            'noindex' => array_key_exists('noindex', $input) ? $input['noindex'] === true : ($existing->noindex ?? false),
        ];
    }

    private function slugProblem(?string $slug, ?Page $existing): ?string
    {
        if ($slug === null || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return 'Use lowercase letters, numbers and single hyphens only.';
        }

        if (strlen($slug) > self::SLUG_MAX) {
            return 'Keep the address to ' . self::SLUG_MAX . ' characters or fewer.';
        }

        if (in_array($slug, self::RESERVED, true) || ($this->addressTaken !== null && ($this->addressTaken)($slug))) {
            return 'That address is used by another part of the site. Choose a different one.';
        }

        $other = $this->database->fetchOne('SELECT id FROM {prefix}pages WHERE slug = :slug', ['slug' => $slug]);

        if ($other !== null && (int) $other['id'] !== ($existing->id ?? 0)) {
            return 'Another page already uses that address.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Page
    {
        return new Page(
            id: (int) $row['id'],
            title: (string) $row['title'],
            slug: (string) $row['slug'],
            content: (string) $row['content'],
            format: ContentFormat::tryFrom((string) $row['content_format']) ?? ContentFormat::Plain,
            // An unrecognised state counts as a draft so a corrupt value fails closed rather than going public.
            state: PublicationState::tryFrom((string) $row['publication_state']) === PublicationState::Published ? PublicationState::Published : PublicationState::Draft,
            inMenu: (int) $row['in_menu'] === 1,
            inFooter: (int) $row['in_footer'] === 1,
            sortOrder: (int) $row['sort_order'],
            description: (string) $row['description'],
            noindex: (int) $row['noindex'] === 1,
            editedBy: $row['edited_by'] === null ? null : (int) $row['edited_by'],
            publishedAt: is_string($row['published_at']) && $row['published_at'] !== '' ? new DateTimeImmutable($row['published_at']) : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
