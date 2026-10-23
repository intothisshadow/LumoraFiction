<?php

/**
 * Creates, edits, publishes and deletes stories, deciding who may do each.
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
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Http\UploadedFile;
use LumoraFiction\Core\Media\CoverImageException;
use LumoraFiction\Core\Media\CoverImageStore;
use LumoraFiction\Helpers\Slug;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\PublishingOverride;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\User;
use LumoraFiction\Models\UserRole;
use LumoraFiction\Models\WorkStatus;

/**
 * Every decision about who may change a story is made here, never in a controller or a view. A story
 * that does not exist and a story the caller may not touch look the same (StoryPermissionException), so
 * the error never confirms that someone else's draft exists. Text is stored exactly as written; escaping
 * is the job of whatever prints it.
 */
final class StoryService
{
    public const TITLE_MAX = 200;

    public const SUMMARY_MAX = 4000;

    public const NOTES_MAX = 4000;

    public const DISCLAIMER_MAX = 2000;

    public const REASON_MAX = 1000;

    public const COVER_ALT_MAX = 250;

    /** The slug column holds 120 characters: room for a ten-digit id, the hyphen and the title words. */
    private const SLUG_WORDS_MAX = 100;

    public function __construct(
        private readonly Database $database,
        private readonly Configuration $config,
        private readonly AuditLogService $audit,
        private readonly TaxonomyService $taxonomy,
        private readonly ?HookManager $hooks = null,
        private readonly ?CoverImageStore $covers = null,
    ) {
    }

    /**
     * An author changes only their own work; editors and administrators may change any. A suspended
     * account can change nothing, because User::can() requires an active account.
     */
    public function canManage(User $actor, Story $story): bool
    {
        if (!$actor->can(Capability::WriteStories)) {
            return false;
        }

        return $story->ownerId === $actor->id || $actor->can(Capability::EditAnyStory);
    }

    /**
     * @param array<string, mixed> $input title, summary, notes, disclaimer, language, work_status
     * @throws StoryPermissionException when the account may not write stories
     * @throws StoryValidationException when the input is not acceptable
     */
    public function create(User $actor, array $input, string $ipAddress = ''): Story
    {
        if (!$actor->can(Capability::WriteStories)) {
            throw new StoryPermissionException('This account may not write stories.');
        }

        $values = $this->validated($input, null);
        $now = date('Y-m-d H:i:s');

        $id = (int) $this->database->transaction(function () use ($actor, $values, $now, $ipAddress): int {
            $id = $this->insert($actor->id, $values, $now);
            $this->audit->record($actor->id, 'story.create', 'story', (string) $id, ['title' => $values['title']], $ipAddress);

            return $id;
        });

        return $this->mustFind($id);
    }

    /**
     * Only the fields present in $input change. The slug never changes, so a published address stays valid.
     *
     * @param array<string, mixed> $input
     * @throws StoryPermissionException
     * @throws StoryValidationException
     */
    public function update(User $actor, int $storyId, array $input, string $ipAddress = ''): Story
    {
        $story = $this->manageable($actor, $storyId);
        $values = $this->validated($input, $story);

        $changed = [];

        foreach ($values as $field => $value) {
            if ($value !== $this->fieldValue($story, $field)) {
                $changed[$field] = $field === 'work_status'
                    ? ['from' => $story->workStatus->value, 'to' => $value]
                    : $field;
            }
        }

        if ($changed === []) {
            return $story;
        }

        $this->database->transaction(function () use ($actor, $story, $values, $changed, $ipAddress): void {
            $this->database->execute(
                'UPDATE {prefix}stories SET title = :title, summary = :summary, notes = :notes, disclaimer = :disclaimer,
                 language = :language, work_status = :work_status, updated_at = :updated WHERE id = :id',
                $values + ['updated' => date('Y-m-d H:i:s'), 'id' => $story->id],
            );

            if (isset($changed['work_status'])) {
                $this->audit->record($actor->id, 'story.work_status', 'story', (string) $story->id, $changed['work_status'], $ipAddress);
            }

            $this->audit->record($actor->id, 'story.update', 'story', (string) $story->id, ['fields' => array_keys($changed)], $ipAddress);
        });

        return $this->mustFind($story->id);
    }

    /**
     * Sets the story's fandoms, characters, tags, rating and the rest; see TaxonomyService::assignToStory()
     * for what the selection may contain.
     *
     * @param array<string, list<int|string>> $selection
     * @throws StoryPermissionException
     * @throws StoryValidationException
     */
    public function classify(User $actor, int $storyId, array $selection, string $ipAddress = ''): Story
    {
        $story = $this->manageable($actor, $storyId);

        $this->database->transaction(function () use ($actor, $story, $selection, $ipAddress): void {
            $this->taxonomy->assignToStory($actor, $story->id, $selection, $ipAddress);
            $this->database->execute('UPDATE {prefix}stories SET updated_at = :updated WHERE id = :id', ['updated' => date('Y-m-d H:i:s'), 'id' => $story->id]);
        });

        return $this->mustFind($story->id);
    }

    /**
     * Sets, replaces, describes or removes the story's cover in one step, so the form saves it with the rest.
     * A picture needs its text alternative, so a cover is never stored without one. Nothing changes when
     * the form carries no picture, no removal and an unchanged description. The old files are removed only
     * after the new ones are safely recorded.
     *
     * @throws StoryPermissionException
     * @param array{x: float, y: float, w: float, h: float}|null $crop the part of a new picture to keep, as fractions
     * @throws StoryValidationException keyed "cover" or "cover_alt"
     */
    public function applyCover(User $actor, int $storyId, ?UploadedFile $upload, string $alt, bool $remove = false, string $ipAddress = '', ?array $crop = null): Story
    {
        $story = $this->manageable($actor, $storyId);
        $uploading = $upload !== null && $upload->wasSent();

        if ($remove && !$uploading) {
            return $story->hasCover() ? $this->storeCover($actor, $story, '', '', 'remove', $ipAddress) : $story;
        }

        if (!$uploading && !$story->hasCover()) {
            return $story;
        }

        $errors = [];
        $alt = $this->singleLine($alt, 'cover_alt', 'Describe the cover for readers who cannot see it.', self::COVER_ALT_MAX, $errors, true);
        $file = '';

        if ($uploading) {
            if ($this->covers === null) {
                $errors['cover'] = 'Covers are not available on this site.';
            } elseif ($upload->problem() !== '') {
                $errors['cover'] = $upload->problem();
            } else {
                try {
                    $file = $this->covers->save($upload->path, $crop);
                } catch (CoverImageException $exception) {
                    $errors['cover'] = $exception->getMessage();
                }
            }
        }

        if ($errors !== []) {
            if ($file !== '') {
                $this->covers?->delete($file);
            }

            throw new StoryValidationException($errors);
        }

        if ($file === '' && $alt === $story->coverAlt) {
            return $story;
        }

        return $this->storeCover($actor, $story, $file === '' ? $story->coverFile : $file, $alt, $file === '' ? 'describe' : ($story->hasCover() ? 'replace' : 'set'), $ipAddress);
    }

    /**
     * Records the new cover, then removes the picture it replaced. A failure while recording removes the new
     * picture instead, so no file is left that no story points at.
     */
    private function storeCover(User $actor, Story $story, string $file, string $alt, string $action, string $ipAddress): Story
    {
        try {
            $this->database->transaction(function () use ($actor, $story, $file, $alt, $action, $ipAddress): void {
                $this->database->execute(
                    'UPDATE {prefix}stories SET cover_file = :file, cover_alt = :alt, updated_at = :updated WHERE id = :id',
                    ['file' => $file, 'alt' => $alt, 'updated' => date('Y-m-d H:i:s'), 'id' => $story->id],
                );
                $this->audit->record($actor->id, 'story.cover', 'story', (string) $story->id, ['action' => $action], $ipAddress);
            });
        } catch (\Throwable $exception) {
            if ($file !== '' && $file !== $story->coverFile) {
                $this->covers?->delete($file);
            }

            throw $exception;
        }

        if ($story->hasCover() && $story->coverFile !== $file) {
            $this->covers?->delete($story->coverFile);
        }

        return $this->mustFind($story->id);
    }

    /**
     * Brings the public story counts of the story's terms back in step. The chapter service calls this
     * when a chapter being published or removed changes whether the story is public.
     */
    public function refreshTermCounts(int $storyId): void
    {
        $this->taxonomy->refreshCountsForStory($storyId);
    }

    /**
     * Whether this person's story goes straight to readers. Staff always do; an administrator's per-writer
     * decision comes next; then the site rule, under which a new writer's first story is held for review and
     * later ones are not once any of their stories has been published.
     */
    public function publishesDirectly(User $actor, Story $story): bool
    {
        if ($actor->can(Capability::ModerateStories)) {
            return true;
        }

        if ($actor->publishingOverride === PublishingOverride::Review) {
            return false;
        }

        if ($actor->publishingOverride === PublishingOverride::Direct || $actor->can(Capability::PublishWithoutReview)) {
            return true;
        }

        if ($this->config->setting('publishing.hold_first_story') === false) {
            return true;
        }

        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM {prefix}stories WHERE owner_id = :owner AND id <> :id AND published_at IS NOT NULL',
            ['owner' => $story->ownerId, 'id' => $story->id],
        ) > 0;
    }

    /**
     * Publishes the story straight away when the caller is trusted to, otherwise puts it in the
     * approval queue. A story already waiting for approval stays there when its own author asks again.
     * Either way the story must already carry the classification the administrator requires.
     *
     * @throws StoryPermissionException
     * @throws StoryValidationException when a required kind of classification is missing
     */
    public function publish(User $actor, int $storyId, string $ipAddress = ''): Story
    {
        $story = $this->manageable($actor, $storyId);
        $target = PublicationRules::submit($story->state, $this->publishesDirectly($actor, $story));

        if ($target === null) {
            return $story;
        }

        $missing = $this->taxonomy->missingRequired($story->id);

        if ($missing !== []) {
            throw new StoryValidationException($missing);
        }

        return $this->transition($story, $target, $actor, $target === PublicationState::Published ? 'story.publish' : 'story.submit', $ipAddress);
    }

    /**
     * Takes a story away from readers. A story still waiting for approval goes back to being a draft.
     *
     * @throws StoryPermissionException
     */
    public function unpublish(User $actor, int $storyId, string $ipAddress = ''): Story
    {
        $story = $this->manageable($actor, $storyId);
        $target = PublicationRules::withdraw($story->state);

        if ($target === null) {
            return $story;
        }

        return $this->transition($story, $target, $actor, $story->state === PublicationState::Pending ? 'story.withdraw' : 'story.unpublish', $ipAddress);
    }

    /**
     * Staff accept a story from the queue. The classification is checked again because the required kinds
     * may have changed since the author sent it.
     *
     * @throws StoryPermissionException when the caller may not review stories or the story does not exist
     * @throws StoryValidationException when the story is no longer waiting, or now lacks required classification
     */
    public function approve(User $actor, int $storyId, string $ipAddress = ''): Story
    {
        $story = $this->reviewable($actor, $storyId);
        $target = PublicationRules::approve($story->state) ?? throw new StoryValidationException(['state' => 'That story is no longer waiting for approval.']);

        $missing = $this->taxonomy->missingRequired($story->id);

        if ($missing !== []) {
            throw new StoryValidationException($missing);
        }

        return $this->transition($story, $target, $actor, 'story.approve', $ipAddress);
    }

    /**
     * Staff send a story back to its author as a draft, with the reason the author will read.
     *
     * @throws StoryPermissionException
     * @throws StoryValidationException when no acceptable reason is given or the story is no longer waiting
     */
    public function reject(User $actor, int $storyId, string $reason, string $ipAddress = ''): Story
    {
        $story = $this->reviewable($actor, $storyId);
        $target = PublicationRules::reject($story->state) ?? throw new StoryValidationException(['state' => 'That story is no longer waiting for approval.']);

        $errors = [];
        $reason = $this->multiLine($reason, 'reason', self::REASON_MAX, $errors);

        if ($reason === '' && $errors === []) {
            $errors['reason'] = 'Tell the author why the story was not approved.';
        }

        if ($errors !== []) {
            throw new StoryValidationException($errors);
        }

        return $this->transition($story, $target, $actor, 'story.reject', $ipAddress, $reason);
    }

    /**
     * Takes every public or waiting story of one writer out of view, keeping the stories, chapters and links.
     * Each story changes in its own transaction, so an interruption leaves the rest to be done by running it again.
     *
     * @return int how many stories were taken out of view
     * @throws StoryPermissionException when the caller may not review stories
     */
    public function unpublishAllByOwner(User $actor, int $ownerId, string $reason = '', string $ipAddress = ''): int
    {
        if (!$actor->can(Capability::ModerateStories)) {
            throw new StoryPermissionException('You may not do that.');
        }

        $errors = [];
        $reason = $this->multiLine($reason, 'reason', self::REASON_MAX, $errors);

        if ($errors !== []) {
            throw new StoryValidationException($errors);
        }

        $count = 0;

        foreach ($this->hydrateAll($this->database->fetchAll(
            'SELECT * FROM {prefix}stories WHERE owner_id = :owner AND publication_state IN (:published, :pending)',
            ['owner' => $ownerId, 'published' => PublicationState::Published->value, 'pending' => PublicationState::Pending->value],
        )) as $story) {
            $target = PublicationRules::withdraw($story->state);

            if ($target !== null) {
                $this->transition($story, $target, $actor, 'story.unpublish', $ipAddress, $reason);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Gives a writer the right to publish without review, or takes it away so every story they send is
     * reviewed. Null returns them to the site-wide rule.
     *
     * @throws StoryPermissionException when the caller may not manage users, or the account is not an author
     */
    public function setPublishingOverride(User $actor, int $userId, ?PublishingOverride $override, string $ipAddress = ''): void
    {
        if (!$actor->can(Capability::ManageUsers)) {
            throw new StoryPermissionException('You may not do that.');
        }

        $role = $this->database->fetchColumn('SELECT role FROM {prefix}users WHERE id = :id', ['id' => $userId]);

        if ($role !== UserRole::Author->value) {
            throw new StoryPermissionException('Only an author can be given this.');
        }

        $this->database->transaction(function () use ($actor, $userId, $override, $ipAddress): void {
            $this->database->execute(
                'UPDATE {prefix}users SET publishing_override = :override, updated_at = :updated WHERE id = :id',
                ['override' => $override?->value, 'updated' => date('Y-m-d H:i:s'), 'id' => $userId],
            );
            $this->audit->record($actor->id, 'user.publishing_override', 'user', (string) $userId, ['to' => $override?->value], $ipAddress);
        });
    }

    /**
     * @return list<Story> the stories waiting for approval, longest waiting first; empty for anyone who may not review
     */
    public function listPending(User $actor, int $limit = 25, int $offset = 0): array
    {
        if (!$actor->can(Capability::ModerateStories)) {
            return [];
        }

        return $this->hydrateAll($this->database->fetchAll(
            'SELECT * FROM {prefix}stories WHERE publication_state = :state ORDER BY updated_at ASC, id ASC LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            ['state' => PublicationState::Pending->value],
        ));
    }

    public function countPending(User $actor): int
    {
        if (!$actor->can(Capability::ModerateStories)) {
            return 0;
        }

        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}stories WHERE publication_state = :state', ['state' => PublicationState::Pending->value]);
    }

    /**
     * Removes the story, its chapters and its classification links together or not at all.
     *
     * @throws StoryPermissionException
     */
    public function delete(User $actor, int $storyId, string $ipAddress = ''): void
    {
        $story = $this->manageable($actor, $storyId);

        $this->database->transaction(function () use ($actor, $story, $ipAddress): void {
            $this->taxonomy->removeStoryLinks($story->id);
            $this->database->execute('DELETE FROM {prefix}chapters WHERE story_id = :id', ['id' => $story->id]);
            $this->database->execute('DELETE FROM {prefix}bookmarks WHERE story_id = :id', ['id' => $story->id]);
            $this->database->execute('DELETE FROM {prefix}reading_progress WHERE story_id = :id', ['id' => $story->id]);
            $this->database->execute('DELETE FROM {prefix}story_follows WHERE story_id = :id', ['id' => $story->id]);
            $this->database->execute('DELETE FROM {prefix}stories WHERE id = :id', ['id' => $story->id]);
            $this->audit->record($actor->id, 'story.delete', 'story', (string) $story->id, ['title' => $story->title, 'owner' => $story->ownerId], $ipAddress);
        });

        // After the rows are gone, so a rolled-back deletion never loses the picture of a story that still exists.
        if ($story->hasCover()) {
            $this->covers?->delete($story->coverFile);
        }
    }

    /**
     * Loads a story in any state. For the writer area and moderation only: a public page must use findPublicBySlug().
     */
    public function find(int $id): ?Story
    {
        $row = $this->database->fetchOne('SELECT * FROM {prefix}stories WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findBySlug(string $slug): ?Story
    {
        $row = $this->database->fetchOne('SELECT * FROM {prefix}stories WHERE slug = :slug', ['slug' => $slug]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * The only way a public page loads a story by address; a draft is never returned.
     */
    public function findPublicBySlug(string $slug): ?Story
    {
        $row = $this->database->fetchOne(
            'SELECT s.* FROM {prefix}stories s WHERE s.slug = :slug AND ' . StoryVisibility::publicCondition('s'),
            ['slug' => $slug],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function isPublic(Story $story): bool
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM {prefix}stories s WHERE s.id = :id AND ' . StoryVisibility::publicCondition('s'),
            ['id' => $story->id],
        ) === 1;
    }

    /**
     * @return list<Story> the owner's stories in any state, most recently changed first
     */
    public function listForOwner(int $ownerId): array
    {
        return $this->hydrateAll($this->database->fetchAll(
            'SELECT * FROM {prefix}stories WHERE owner_id = :owner ORDER BY updated_at DESC, id DESC',
            ['owner' => $ownerId],
        ));
    }

    /**
     * The writer's list. An author sees their own stories; someone who may edit any story can ask for every
     * story with scope "all". The owner restriction is applied here, not by the screen, so a changed address
     * cannot widen it.
     *
     * @param array{scope?: string, state?: string, search?: string} $filters
     * @return list<Story> most recently changed first
     */
    public function listForWriter(User $actor, array $filters, int $limit = 25, int $offset = 0): array
    {
        if (!$actor->can(Capability::WriteStories)) {
            return [];
        }

        [$where, $params] = $this->writerFilter($actor, $filters);

        return $this->hydrateAll($this->database->fetchAll(
            'SELECT s.* FROM {prefix}stories s WHERE ' . $where . ' ORDER BY s.updated_at DESC, s.id DESC LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
            $params,
        ));
    }

    /**
     * @param array{scope?: string, state?: string, search?: string} $filters
     */
    public function countForWriter(User $actor, array $filters): int
    {
        if (!$actor->can(Capability::WriteStories)) {
            return 0;
        }

        [$where, $params] = $this->writerFilter($actor, $filters);

        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}stories s WHERE ' . $where, $params);
    }

    /**
     * @return array<string, int> the owner's story count for every publication state, including those with none
     */
    public function countsByState(int $ownerId): array
    {
        $counts = array_fill_keys(array_map(static fn (PublicationState $state): string => $state->value, PublicationState::cases()), 0);

        foreach ($this->database->fetchAll(
            'SELECT publication_state, COUNT(*) AS total FROM {prefix}stories WHERE owner_id = :owner GROUP BY publication_state',
            ['owner' => $ownerId],
        ) as $row) {
            if (isset($counts[$row['publication_state']])) {
                $counts[$row['publication_state']] = (int) $row['total'];
            }
        }

        return $counts;
    }

    /**
     * Applies one action to several stories. Each story is checked on its own, so a story the caller may
     * not change, or one that cannot be published yet, is skipped and never stops the others.
     *
     * @param list<int> $storyIds
     * @return array{done: int, skipped: int}
     */
    public function bulk(User $actor, string $action, array $storyIds, string $ipAddress = ''): array
    {
        $done = 0;
        $skipped = 0;

        foreach (array_slice(array_values(array_unique($storyIds)), 0, 100) as $id) {
            try {
                match ($action) {
                    'publish' => $this->publish($actor, $id, $ipAddress),
                    'unpublish' => $this->unpublish($actor, $id, $ipAddress),
                    'delete' => $this->delete($actor, $id, $ipAddress),
                    default => throw new StoryValidationException(['bulk_action' => 'Choose what to do with the selected stories.']),
                };
                $done++;
            } catch (StoryPermissionException) {
                $skipped++;
            } catch (StoryValidationException $exception) {
                if (isset($exception->errors['bulk_action'])) {
                    throw $exception;
                }

                $skipped++;
            }
        }

        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * @return list<Story> public stories, newest first
     */
    public function listPublic(int $limit = 20, int $offset = 0): array
    {
        return $this->hydrateAll($this->database->fetchAll(
            'SELECT s.* FROM {prefix}stories s WHERE ' . StoryVisibility::publicCondition('s')
            . ' ORDER BY s.published_at DESC, s.id DESC LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset),
        ));
    }

    /**
     * Turns rows selected from the stories table (alias s, all columns) into stories, for the listing queries that
     * live outside this class.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<Story>
     */
    public function fromRows(array $rows): array
    {
        return $this->hydrateAll($rows);
    }

    /**
     * Sets the story's total to the sum over its chapters. Called by the chapter service after any change.
     */
    public function refreshWordCount(int $storyId): void
    {
        $this->database->execute(
            'UPDATE {prefix}stories SET word_count = (SELECT COALESCE(SUM(word_count), 0) FROM {prefix}chapters WHERE story_id = :chapter_story),
             updated_at = :updated WHERE id = :id',
            ['chapter_story' => $storyId, 'updated' => date('Y-m-d H:i:s'), 'id' => $storyId],
        );
    }

    /**
     * @throws StoryPermissionException when the story is missing or the caller may not change it
     */
    public function manageable(User $actor, int $storyId): Story
    {
        $story = $this->find($storyId);

        if ($story === null || !$this->canManage($actor, $story)) {
            throw new StoryPermissionException('That story is not available.');
        }

        return $story;
    }

    /**
     * @param array{scope?: string, state?: string, search?: string} $filters
     * @return array{string, array<string, mixed>}
     */
    private function writerFilter(User $actor, array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (($filters['scope'] ?? 'mine') !== 'all' || !$actor->can(Capability::EditAnyStory)) {
            $where[] = 's.owner_id = :owner';
            $params['owner'] = $actor->id;
        }

        $state = PublicationState::tryFrom($filters['state'] ?? '');

        if ($state !== null) {
            $where[] = 's.publication_state = :state';
            $params['state'] = $state->value;
        }

        $search = mb_substr(trim($filters['search'] ?? ''), 0, 100, 'UTF-8');

        if ($search !== '') {
            $where[] = 's.title LIKE :search';
            $params['search'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{title: string, summary: string, notes: string, disclaimer: string, language: string, work_status: string}
     */
    private function validated(array $input, ?Story $existing): array
    {
        $errors = [];
        $get = static fn (string $field, string $fallback): mixed => array_key_exists($field, $input) ? $input[$field] : $fallback;

        $title = $this->singleLine($get('title', $existing->title ?? ''), 'title', 'Give the story a title.', self::TITLE_MAX, $errors, true);
        $summary = $this->multiLine($get('summary', $existing->summary ?? ''), 'summary', self::SUMMARY_MAX, $errors);
        $notes = $this->multiLine($get('notes', $existing->notes ?? ''), 'notes', self::NOTES_MAX, $errors);
        $disclaimer = $this->multiLine($get('disclaimer', $existing->disclaimer ?? ''), 'disclaimer', self::DISCLAIMER_MAX, $errors);

        $language = $get('language', $existing->language ?? 'en');
        $language = is_string($language) ? strtolower(trim($language)) : '';

        if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?\z/', $language) !== 1) {
            $errors['language'] = 'Choose a language.';
        }

        $statusInput = $get('work_status', ($existing->workStatus ?? WorkStatus::Ongoing)->value);
        $status = is_string($statusInput) ? WorkStatus::tryFrom($statusInput) : null;

        if ($status === null) {
            $errors['work_status'] = 'Choose where the story stands.';
        } elseif ($status === WorkStatus::Abandoned && $existing?->workStatus !== WorkStatus::Abandoned && $this->config->setting('stories.allow_abandoned') !== true) {
            $errors['work_status'] = 'This archive does not allow stories to be marked abandoned.';
        }

        if ($errors !== []) {
            throw new StoryValidationException($errors);
        }

        return [
            'title' => $title,
            'summary' => $summary,
            'notes' => $notes,
            'disclaimer' => $disclaimer,
            'language' => $language,
            'work_status' => $status->value ?? WorkStatus::Ongoing->value,
        ];
    }

    /**
     * @param array<string, string> $errors
     */
    private function singleLine(mixed $value, string $field, string $emptyMessage, int $max, array &$errors, bool $required): string
    {
        $text = is_string($value) ? trim($value) : '';

        if (!is_string($value) || !mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $text) === 1) {
            $errors[$field] = 'This contains characters that are not allowed.';

            return '';
        }

        if ($required && $text === '') {
            $errors[$field] = $emptyMessage;
        } elseif (mb_strlen($text, 'UTF-8') > $max) {
            $errors[$field] = "Keep this to {$max} characters or fewer.";
        }

        return $text;
    }

    /**
     * @param array<string, string> $errors
     */
    private function multiLine(mixed $value, string $field, int $max, array &$errors): string
    {
        $text = is_string($value) ? trim(str_replace(["\r\n", "\r"], "\n", $value)) : '';

        if (!is_string($value) || !mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text) === 1) {
            $errors[$field] = 'This contains characters that are not allowed.';

            return '';
        }

        if (mb_strlen($text, 'UTF-8') > $max) {
            $errors[$field] = "Keep this to {$max} characters or fewer.";
        }

        return $text;
    }

    /**
     * @param array{title: string, summary: string, notes: string, disclaimer: string, language: string, work_status: string} $values
     */
    private function insert(int $ownerId, array $values, string $now): int
    {
        $words = Slug::fromText($values['title'], self::SLUG_WORDS_MAX, 'story');

        // The address is the story's id followed by its title words, so two stories with one title never collide
        // and never need a counter. The id is only known after the insert, hence the throwaway first value; the
        // caller's transaction covers both statements.
        $id = (int) $this->database->insertGetId(
            'INSERT INTO {prefix}stories (owner_id, title, slug, summary, notes, disclaimer, language, work_status,
             publication_state, word_count, published_at, created_at, updated_at)
             VALUES (:owner, :title, :slug, :summary, :notes, :disclaimer, :language, :work_status, :state, 0, NULL, :created, :updated)',
            [
                'owner' => $ownerId,
                'title' => $values['title'],
                'slug' => 'new-' . bin2hex(random_bytes(8)),
                'summary' => $values['summary'],
                'notes' => $values['notes'],
                'disclaimer' => $values['disclaimer'],
                'language' => $values['language'],
                'work_status' => $values['work_status'],
                'state' => PublicationState::Draft->value,
                'created' => $now,
                'updated' => $now,
            ],
        );

        $this->database->execute('UPDATE {prefix}stories SET slug = :slug WHERE id = :id', ['slug' => $id . '-' . $words, 'id' => $id]);

        return $id;
    }

    /**
     * @throws StoryPermissionException when the caller may not review stories or the story does not exist
     */
    private function reviewable(User $actor, int $storyId): Story
    {
        $story = $this->find($storyId);

        if ($story === null || !$actor->can(Capability::ModerateStories)) {
            throw new StoryPermissionException('That story is not available.');
        }

        return $story;
    }

    /**
     * $reason is a rejection note when the new state is a draft, and otherwise only goes to the audit log.
     * Submitting or publishing clears an earlier rejection, so the note never outlives the decision it explains.
     */
    private function transition(Story $story, PublicationState $to, User $actor, string $auditAction, string $ipAddress, string $reason = ''): Story
    {
        $rejecting = $auditAction === 'story.reject';

        $this->database->transaction(function () use ($story, $to, $actor, $auditAction, $ipAddress, $reason, $rejecting): void {
            $now = date('Y-m-d H:i:s');
            $keepsNote = !in_array($to, [PublicationState::Pending, PublicationState::Published], true);

            $this->database->execute(
                'UPDATE {prefix}stories SET publication_state = :state, published_at = :published, rejection_reason = :reason,
                 rejected_at = :rejected, updated_at = :updated WHERE id = :id',
                [
                    'state' => $to->value,
                    // The first publication date is kept when a story is unpublished and published again.
                    'published' => $to === PublicationState::Published && $story->publishedAt === null ? $now : $story->publishedAt?->format('Y-m-d H:i:s'),
                    'reason' => $rejecting ? $reason : ($keepsNote && $story->rejectionReason !== '' ? $story->rejectionReason : null),
                    'rejected' => $rejecting ? $now : ($keepsNote ? $story->rejectedAt?->format('Y-m-d H:i:s') : null),
                    'updated' => $now,
                    'id' => $story->id,
                ],
            );
            $this->taxonomy->refreshCountsForStory($story->id);

            $details = ['from' => $story->state->value, 'to' => $to->value];

            if ($reason !== '') {
                $details['reason'] = $reason;
            }

            $this->audit->record($actor->id, $auditAction, 'story', (string) $story->id, $details, $ipAddress);
        });

        $changed = $this->mustFind($story->id);

        // Other features (notifications, mail) listen here; it runs after the change is saved so a listener
        // that fails cannot undo it.
        $this->hooks?->doAction('story_state_changed', $changed, $story->state, $actor, $reason);

        return $changed;
    }

    private function fieldValue(Story $story, string $field): string
    {
        return match ($field) {
            'title' => $story->title,
            'summary' => $story->summary,
            'notes' => $story->notes,
            'disclaimer' => $story->disclaimer,
            'language' => $story->language,
            default => $story->workStatus->value,
        };
    }

    private function mustFind(int $id): Story
    {
        return $this->find($id) ?? throw new StoryPermissionException('That story is not available.');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Story>
     */
    private function hydrateAll(array $rows): array
    {
        return array_map(fn (array $row): Story => $this->hydrate($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Story
    {
        return new Story(
            id: (int) $row['id'],
            ownerId: (int) $row['owner_id'],
            title: (string) $row['title'],
            slug: (string) $row['slug'],
            summary: (string) $row['summary'],
            notes: (string) $row['notes'],
            disclaimer: (string) $row['disclaimer'],
            language: (string) $row['language'],
            workStatus: WorkStatus::tryFrom((string) $row['work_status']) ?? WorkStatus::Ongoing,
            // An unrecognised state counts as a draft so a corrupt value fails closed rather than going public.
            state: PublicationState::tryFrom((string) $row['publication_state']) ?? PublicationState::Draft,
            wordCount: (int) $row['word_count'],
            publishedAt: is_string($row['published_at']) && $row['published_at'] !== '' ? new DateTimeImmutable($row['published_at']) : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
            rejectionReason: is_string($row['rejection_reason'] ?? null) ? $row['rejection_reason'] : '',
            rejectedAt: is_string($row['rejected_at'] ?? null) && $row['rejected_at'] !== '' ? new DateTimeImmutable($row['rejected_at']) : null,
            // A name that is not one the store made is treated as no cover, so a damaged row cannot point anywhere.
            coverFile: is_string($row['cover_file'] ?? null) && CoverImageStore::isValidName($row['cover_file']) ? $row['cover_file'] : '',
            coverAlt: is_string($row['cover_alt'] ?? null) ? $row['cover_alt'] : '',
        );
    }
}
