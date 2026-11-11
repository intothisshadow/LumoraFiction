<?php

/**
 * Collections: curated groups of stories that may hold other authors' work.
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
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Helpers\Slug;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\Collection;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\User;

/**
 * The curator and staff who may edit any story manage a collection and its queue. A story goes into a collection only by
 * its own author's hand, so a curator never adds, changes or exposes someone else's work, and an author can always take
 * their story out again. Visibility follows the story: every public list starts from the story-visibility rule, so a draft,
 * pending or unpublished story is never listed to anyone who could not already open it, even inside a public collection.
 */
final class CollectionService
{
    public const TITLE_MAX = 200;

    public const DESCRIPTION_MAX = 2000;

    public const NOTE_MAX = 300;

    public const APPROVED = 'approved';

    public const PENDING = 'pending';

    private const FORBIDDEN = '/[\p{Cc}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    private const SLUG_WORDS_MAX = 100;

    public function __construct(
        private readonly Database $database,
        private readonly Configuration $config,
        private readonly AuditLogService $audit,
        private readonly StoryService $stories,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    public function mayCreate(User $actor): bool
    {
        return GroupingSettings::mayStartCollection($this->config, $actor);
    }

    public function canManage(User $actor, Collection $collection): bool
    {
        return $actor->can(Capability::WriteStories) && ($collection->ownerId === $actor->id || $actor->can(Capability::EditAnyStory));
    }

    /**
     * @param array<string, mixed> $input title, description, visibility, accepts_submissions, requires_approval
     * @throws GroupingException
     */
    public function create(User $actor, array $input, string $ipAddress = ''): Collection
    {
        if (!$this->mayCreate($actor)) {
            throw new GroupingException('This account may not start a collection on this site.');
        }

        $values = $this->validated($input);
        $now = date('Y-m-d H:i:s');

        $id = (int) $this->database->transaction(function () use ($actor, $values, $now, $ipAddress): int {
            $id = (int) $this->database->insertGetId(
                'INSERT INTO {prefix}collections (owner_id, title, slug, description, visibility, accepts_submissions, requires_approval, created_at, updated_at)
                 VALUES (:owner, :title, :slug, :description, :visibility, :accepts, :approval, :created, :updated)',
                [
                    'owner' => $actor->id,
                    'title' => $values['title'],
                    'slug' => 'new-' . bin2hex(random_bytes(8)),
                    'description' => $values['description'],
                    'visibility' => $values['visibility'],
                    'accepts' => (int) $values['accepts_submissions'],
                    'approval' => (int) $values['requires_approval'],
                    'created' => $now,
                    'updated' => $now,
                ],
            );
            $this->database->execute('UPDATE {prefix}collections SET slug = :slug WHERE id = :id', ['slug' => $id . '-' . Slug::fromText($values['title'], self::SLUG_WORDS_MAX, 'collection'), 'id' => $id]);
            $this->audit->record($actor->id, 'collection.create', 'collection', (string) $id, ['title' => $values['title'], 'visibility' => $values['visibility']], $ipAddress);

            return $id;
        });

        return $this->mustFind($id);
    }

    /**
     * @param array<string, mixed> $input
     * @throws GroupingException
     */
    public function update(User $actor, int $collectionId, array $input, string $ipAddress = ''): Collection
    {
        $collection = $this->manageable($actor, $collectionId);
        $values = $this->validated($input);

        $this->database->transaction(function () use ($actor, $collection, $values, $ipAddress): void {
            $this->database->execute(
                'UPDATE {prefix}collections SET title = :title, description = :description, visibility = :visibility, accepts_submissions = :accepts,
                 requires_approval = :approval, updated_at = :updated WHERE id = :id',
                [
                    'title' => $values['title'],
                    'description' => $values['description'],
                    'visibility' => $values['visibility'],
                    'accepts' => (int) $values['accepts_submissions'],
                    'approval' => (int) $values['requires_approval'],
                    'updated' => date('Y-m-d H:i:s'),
                    'id' => $collection->id,
                ],
            );
            $this->audit->record($actor->id, 'collection.update', 'collection', (string) $collection->id, ['title' => $values['title'], 'visibility' => $values['visibility']], $ipAddress);
        });

        return $this->mustFind($collection->id);
    }

    /**
     * Removes the collection and its links. The stories are not touched.
     *
     * @throws GroupingException
     */
    public function delete(User $actor, int $collectionId, string $ipAddress = ''): void
    {
        $collection = $this->manageable($actor, $collectionId);
        $approved = array_map(
            static fn (array $row): int => (int) $row['story_id'],
            $this->database->fetchAll('SELECT story_id FROM {prefix}collection_stories WHERE collection_id = :id AND state = :state', ['id' => $collection->id, 'state' => self::APPROVED]),
        );

        $this->database->transaction(function () use ($actor, $collection, $ipAddress): void {
            $this->database->execute('DELETE FROM {prefix}collection_stories WHERE collection_id = :id', ['id' => $collection->id]);
            $this->database->execute('DELETE FROM {prefix}collections WHERE id = :id', ['id' => $collection->id]);
            $this->audit->record($actor->id, 'collection.delete', 'collection', (string) $collection->id, ['title' => $collection->title], $ipAddress);
        });

        foreach ($approved as $storyId) {
            $this->hooks?->doAction('story_removed_from_collection', $storyId, $collection->id);
        }
    }

    public function find(int $id): ?Collection
    {
        $row = $this->database->fetchOne('SELECT * FROM {prefix}collections WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findBySlug(string $slug): ?Collection
    {
        $row = $this->database->fetchOne('SELECT * FROM {prefix}collections WHERE slug = :slug', ['slug' => $slug]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * A collection by its address for someone looking at it: a public one for anybody, a private one only for its curator
     * and staff who may edit any story. Anyone else gets null, the same as for an address that does not exist.
     */
    public function findVisible(string $slug, ?User $viewer): ?Collection
    {
        $collection = $this->findBySlug($slug);

        if ($collection === null) {
            return null;
        }

        if ($collection->isPublic || ($viewer !== null && $this->canManage($viewer, $collection))) {
            return $collection;
        }

        return null;
    }

    /**
     * The collections a person manages, or every collection for staff who ask for all.
     *
     * @return list<array{collection: Collection, approved: int, pending: int, owner: string}>
     */
    public function listFor(User $actor, bool $all = false): array
    {
        if (!$actor->can(Capability::WriteStories)) {
            return [];
        }

        $everyone = $all && $actor->can(Capability::EditAnyStory);
        $rows = $this->database->fetchAll(
            'SELECT c.*, u.display_name AS owner_name,
             (SELECT COUNT(*) FROM {prefix}collection_stories cs WHERE cs.collection_id = c.id AND cs.state = \'approved\') AS approved_count,
             (SELECT COUNT(*) FROM {prefix}collection_stories cs WHERE cs.collection_id = c.id AND cs.state = \'pending\') AS pending_count
             FROM {prefix}collections c LEFT JOIN {prefix}users u ON u.id = c.owner_id'
            . ($everyone ? '' : ' WHERE c.owner_id = :owner') . ' ORDER BY c.updated_at DESC, c.id DESC LIMIT 500',
            $everyone ? [] : ['owner' => $actor->id],
        );

        return array_map(
            fn (array $row): array => ['collection' => $this->hydrate($row), 'approved' => (int) $row['approved_count'], 'pending' => (int) $row['pending_count'], 'owner' => (string) ($row['owner_name'] ?? '')],
            $rows,
        );
    }

    /**
     * Every story in the collection in the given state, with who offered it, for the curator's screen.
     *
     * @return list<array{story: Story, state: string, addedBy: int, note: string, createdAt: DateTimeImmutable}>
     */
    public function members(int $collectionId, ?string $state = null): array
    {
        $rows = $this->database->fetchAll(
            'SELECT s.*, cs.state AS cs_state, cs.added_by AS cs_added_by, cs.note AS cs_note, cs.created_at AS cs_created
             FROM {prefix}collection_stories cs JOIN {prefix}stories s ON s.id = cs.story_id
             WHERE cs.collection_id = :id' . ($state === null ? '' : ' AND cs.state = :state') . ' ORDER BY cs.created_at DESC, s.id DESC',
            $state === null ? ['id' => $collectionId] : ['id' => $collectionId, 'state' => $state],
        );
        $stories = $this->stories->fromRows($rows);
        $members = [];

        foreach ($rows as $index => $row) {
            $members[] = [
                'story' => $stories[$index],
                'state' => (string) $row['cs_state'],
                'addedBy' => (int) $row['cs_added_by'],
                'note' => (string) $row['cs_note'],
                'createdAt' => new DateTimeImmutable((string) $row['cs_created']),
            ];
        }

        return $members;
    }

    /**
     * Public collections that take submissions, other than the person's own, for the screen where they offer a story.
     *
     * @return list<Collection>
     */
    public function open(User $actor): array
    {
        return array_map($this->hydrate(...), $this->database->fetchAll(
            'SELECT * FROM {prefix}collections WHERE visibility = \'public\' AND accepts_submissions = 1 AND owner_id <> :owner ORDER BY title ASC, id ASC LIMIT 500',
            ['owner' => $actor->id],
        ));
    }

    /**
     * What a person has offered to collections they do not curate, with where each stands.
     *
     * @return list<array{collection: Collection, story: Story, state: string}>
     */
    public function submissionsBy(User $actor): array
    {
        $rows = $this->database->fetchAll(
            'SELECT c.id AS c_id, s.*, cs.state AS cs_state
             FROM {prefix}collection_stories cs JOIN {prefix}collections c ON c.id = cs.collection_id JOIN {prefix}stories s ON s.id = cs.story_id
             WHERE s.owner_id = :owner AND c.owner_id <> :owner2 ORDER BY cs.created_at DESC LIMIT 500',
            ['owner' => $actor->id, 'owner2' => $actor->id],
        );
        $stories = $this->stories->fromRows($rows);
        $entries = [];

        foreach ($rows as $index => $row) {
            $collection = $this->find((int) $row['c_id']);

            if ($collection !== null) {
                $entries[] = ['collection' => $collection, 'story' => $stories[$index], 'state' => (string) $row['cs_state']];
            }
        }

        return $entries;
    }

    /**
     * Puts one of the actor's own stories in a collection. The curator's own stories go straight in. A story offered to
     * someone else's collection needs the collection to be public and open to submissions, and waits for approval when
     * the curator asks for that.
     *
     * @return string approved or pending
     * @throws GroupingException
     */
    public function submit(User $actor, int $collectionId, int $storyId, string $note = '', string $ipAddress = ''): string
    {
        $collection = $this->find($collectionId);
        $story = $this->stories->find($storyId);

        if ($collection === null || $story === null || $story->isTrashed() || !$actor->can(Capability::WriteStories) || $story->ownerId !== $actor->id) {
            throw new GroupingException('That story or collection is not available.');
        }

        $own = $collection->ownerId === $actor->id;

        if (!$own && (!$collection->isPublic || !$collection->acceptsSubmissions)) {
            throw new GroupingException('That collection is not taking submissions.');
        }

        $note = trim((string) preg_replace('/\s+/u', ' ', $note));

        if (mb_strlen($note, 'UTF-8') > self::NOTE_MAX || preg_match(self::FORBIDDEN, $note) === 1) {
            throw new GroupingException('The note can be at most ' . self::NOTE_MAX . ' characters, without hidden characters.', ['note' => 'The note can be at most ' . self::NOTE_MAX . ' characters.']);
        }

        if ($this->state($collection->id, $story->id) !== null) {
            throw new GroupingException('That story is already in this collection, or waiting to be.');
        }

        $state = $own || !$collection->requiresApproval ? self::APPROVED : self::PENDING;

        $this->database->transaction(function () use ($actor, $collection, $story, $state, $note, $ipAddress): void {
            $this->database->execute(
                'INSERT INTO {prefix}collection_stories (collection_id, story_id, added_by, state, note, created_at) VALUES (:collection, :story, :by, :state, :note, :created)',
                ['collection' => $collection->id, 'story' => $story->id, 'by' => $actor->id, 'state' => $state, 'note' => $note, 'created' => date('Y-m-d H:i:s')],
            );
            $this->touch($collection->id);
            $this->audit->record($actor->id, 'collection.submit', 'collection', (string) $collection->id, ['story' => $story->id, 'state' => $state], $ipAddress);
        });

        if ($state === self::APPROVED) {
            $this->hooks?->doAction('story_added_to_collection', $story->id, $collection->id);
        }

        return $state;
    }

    /**
     * @throws GroupingException
     */
    public function approve(User $actor, int $collectionId, int $storyId, string $ipAddress = ''): void
    {
        $collection = $this->manageable($actor, $collectionId);

        if ($this->state($collection->id, $storyId) !== self::PENDING) {
            throw new GroupingException('That story is not waiting for approval.');
        }

        $this->database->transaction(function () use ($actor, $collection, $storyId, $ipAddress): void {
            $this->database->execute('UPDATE {prefix}collection_stories SET state = :state WHERE collection_id = :collection AND story_id = :story', ['state' => self::APPROVED, 'collection' => $collection->id, 'story' => $storyId]);
            $this->touch($collection->id);
            $this->audit->record($actor->id, 'collection.approve', 'collection', (string) $collection->id, ['story' => $storyId], $ipAddress);
        });

        $this->hooks?->doAction('story_added_to_collection', $storyId, $collection->id);
    }

    /**
     * The curator takes a story out (or turns a submission down).
     *
     * @throws GroupingException
     */
    public function remove(User $actor, int $collectionId, int $storyId, string $ipAddress = ''): void
    {
        $collection = $this->manageable($actor, $collectionId);

        $this->detach($actor, $collection, $storyId, 'collection.remove', $ipAddress);
    }

    /**
     * An author takes their own story out of any collection it is in, whoever curates it.
     *
     * @throws GroupingException
     */
    public function withdraw(User $actor, int $collectionId, int $storyId, string $ipAddress = ''): void
    {
        $collection = $this->find($collectionId);
        $story = $this->stories->find($storyId);

        if ($collection === null || $story === null || $story->ownerId !== $actor->id) {
            throw new GroupingException('That story or collection is not available.');
        }

        $this->detach($actor, $collection, $storyId, 'collection.withdraw', $ipAddress);
    }

    // ---- what the public sees ------------------------------------------------------------------

    /**
     * The approved stories a visitor may open, newest addition first. Anything else is left out.
     *
     * @return list<Story>
     */
    public function publicStories(Collection $collection, int $limit, int $offset = 0): array
    {
        return $this->stories->fromRows($this->database->fetchAll(
            'SELECT s.* FROM {prefix}collection_stories cs JOIN {prefix}stories s ON s.id = cs.story_id
             WHERE cs.collection_id = :id AND cs.state = \'approved\' AND ' . StoryVisibility::publicCondition('s')
            . ' ORDER BY cs.created_at DESC, s.id DESC LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            ['id' => $collection->id],
        ));
    }

    public function publicCount(int $collectionId): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM {prefix}collection_stories cs JOIN {prefix}stories s ON s.id = cs.story_id
             WHERE cs.collection_id = :id AND cs.state = \'approved\' AND ' . StoryVisibility::publicCondition('s'),
            ['id' => $collectionId],
        );
    }

    /**
     * Public collections with at least one story a visitor can open.
     *
     * @return list<Collection>
     */
    public function listPublic(int $limit, int $offset = 0): array
    {
        return array_map($this->hydrate(...), $this->database->fetchAll(
            'SELECT c.* FROM {prefix}collections c WHERE ' . self::hasPublicStory('c') . ' ORDER BY c.title ASC, c.id ASC LIMIT ' . max(1, min(50000, $limit)) . ' OFFSET ' . max(0, $offset),
        ));
    }

    public function countListPublic(): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}collections c WHERE ' . self::hasPublicStory('c'));
    }

    /**
     * The public collections a story has been approved into, for the story page.
     *
     * @return list<Collection>
     */
    public function forStory(int $storyId): array
    {
        return array_map($this->hydrate(...), $this->database->fetchAll(
            'SELECT c.* FROM {prefix}collections c JOIN {prefix}collection_stories cs ON cs.collection_id = c.id
             WHERE cs.story_id = :story AND cs.state = \'approved\' AND c.visibility = \'public\' ORDER BY c.title ASC, c.id ASC LIMIT 50',
            ['story' => $storyId],
        ));
    }

    /**
     * The collections a story is in, in any state and visibility, for the owner's own screen and the export.
     *
     * @return list<array{collection: Collection, state: string}>
     */
    public function allForStory(int $storyId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT c.*, cs.state AS cs_state FROM {prefix}collections c JOIN {prefix}collection_stories cs ON cs.collection_id = c.id WHERE cs.story_id = :story ORDER BY c.title ASC LIMIT 200',
            ['story' => $storyId],
        );

        return array_map(fn (array $row): array => ['collection' => $this->hydrate($row), 'state' => (string) $row['cs_state']], $rows);
    }

    /**
     * The collections each of these stories is in, in any state and visibility, for an export.
     *
     * @param list<int> $storyIds
     * @return array<int, list<array{collection: Collection, state: string, addedBy: int, note: string}>> story id => memberships
     */
    public function membershipFor(array $storyIds): array
    {
        if ($storyIds === []) {
            return [];
        }

        $map = [];

        foreach ($this->database->fetchAll(
            'SELECT c.*, cs.story_id AS member_story, cs.state AS member_state, cs.added_by AS member_added_by, cs.note AS member_note
             FROM {prefix}collections c JOIN {prefix}collection_stories cs ON cs.collection_id = c.id
             WHERE cs.story_id IN (' . implode(',', array_map('intval', $storyIds)) . ') ORDER BY c.id',
        ) as $row) {
            $map[(int) $row['member_story']][] = [
                'collection' => $this->hydrate($row),
                'state' => (string) $row['member_state'],
                'addedBy' => (int) $row['member_added_by'],
                'note' => (string) $row['member_note'],
            ];
        }

        return $map;
    }

    private static function hasPublicStory(string $alias): string
    {
        return "{$alias}.visibility = 'public' AND EXISTS (SELECT 1 FROM {prefix}collection_stories pcs JOIN {prefix}stories s ON s.id = pcs.story_id WHERE pcs.collection_id = {$alias}.id AND pcs.state = 'approved' AND "
            . StoryVisibility::publicCondition('s') . ')';
    }

    // ---- internals -----------------------------------------------------------------------------

    /**
     * @return array{title: string, description: string, visibility: string, accepts_submissions: bool, requires_approval: bool}
     * @throws GroupingException
     */
    private function validated(array $input): array
    {
        $errors = [];
        $title = trim((string) preg_replace('/\s+/u', ' ', is_string($input['title'] ?? null) ? $input['title'] : ''));
        $description = trim(str_replace(["\r\n", "\r"], "\n", is_string($input['description'] ?? null) ? $input['description'] : ''));
        $visibility = is_string($input['visibility'] ?? null) ? $input['visibility'] : 'public';

        if ($title === '') {
            $errors['title'] = 'Give the collection a title.';
        } elseif (mb_strlen($title, 'UTF-8') > self::TITLE_MAX) {
            $errors['title'] = 'The title can be at most ' . self::TITLE_MAX . ' characters.';
        } elseif (preg_match(self::FORBIDDEN, $title) === 1) {
            $errors['title'] = 'The title cannot hold hidden or control characters.';
        }

        if (mb_strlen($description, 'UTF-8') > self::DESCRIPTION_MAX) {
            $errors['description'] = 'The description can be at most ' . self::DESCRIPTION_MAX . ' characters.';
        } elseif (preg_match('/[^\P{Cc}\n\t]/u', $description) === 1) {
            $errors['description'] = 'The description cannot hold control characters.';
        }

        if (!in_array($visibility, ['public', 'private'], true)) {
            $errors['visibility'] = 'Choose whether the collection is public or private.';
        }

        if ($errors !== []) {
            throw new GroupingException('The collection details are not valid.', $errors);
        }

        $public = $visibility === 'public';

        return [
            'title' => $title,
            'description' => $description,
            // A private collection takes no submissions: nobody else could even find it to offer a story.
            'accepts_submissions' => $public && !empty($input['accepts_submissions']),
            'requires_approval' => !array_key_exists('requires_approval', $input) || !empty($input['requires_approval']),
            'visibility' => $visibility,
        ];
    }

    /**
     * @throws GroupingException
     */
    private function manageable(User $actor, int $collectionId): Collection
    {
        $collection = $this->find($collectionId);

        if ($collection === null || !$this->canManage($actor, $collection)) {
            throw new GroupingException('That collection is not available.');
        }

        return $collection;
    }

    private function mustFind(int $id): Collection
    {
        return $this->find($id) ?? throw new GroupingException('That collection is not available.');
    }

    private function state(int $collectionId, int $storyId): ?string
    {
        $state = $this->database->fetchColumn('SELECT state FROM {prefix}collection_stories WHERE collection_id = :collection AND story_id = :story', ['collection' => $collectionId, 'story' => $storyId]);

        return is_string($state) ? $state : null;
    }

    /**
     * @throws GroupingException
     */
    private function detach(User $actor, Collection $collection, int $storyId, string $action, string $ipAddress): void
    {
        $state = $this->state($collection->id, $storyId);

        if ($state === null) {
            throw new GroupingException('That story is not in this collection.');
        }

        $this->database->transaction(function () use ($actor, $collection, $storyId, $action, $ipAddress): void {
            $this->database->execute('DELETE FROM {prefix}collection_stories WHERE collection_id = :collection AND story_id = :story', ['collection' => $collection->id, 'story' => $storyId]);
            $this->touch($collection->id);
            $this->audit->record($actor->id, $action, 'collection', (string) $collection->id, ['story' => $storyId], $ipAddress);
        });

        if ($state === self::APPROVED) {
            $this->hooks?->doAction('story_removed_from_collection', $storyId, $collection->id);
        }
    }

    private function touch(int $collectionId): void
    {
        $this->database->execute('UPDATE {prefix}collections SET updated_at = :updated WHERE id = :id', ['updated' => date('Y-m-d H:i:s'), 'id' => $collectionId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Collection
    {
        return new Collection(
            (int) $row['id'],
            (int) $row['owner_id'],
            (string) $row['title'],
            (string) $row['slug'],
            (string) $row['description'],
            (string) $row['visibility'] === 'public',
            (int) $row['accepts_submissions'] === 1,
            (int) $row['requires_approval'] === 1,
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
