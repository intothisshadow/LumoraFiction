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
use LumoraFiction\Helpers\Slug;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\User;
use LumoraFiction\Models\WorkStatus;
use PDOException;

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

    private const SLUG_BASE_MAX = 100;

    private const SLUG_ATTEMPTS = 5;

    public function __construct(
        private readonly Database $database,
        private readonly Configuration $config,
        private readonly AuditLogService $audit,
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
     * Publishes the story straight away when the caller is trusted to, otherwise puts it in the
     * approval queue. A story already waiting for approval stays there when its own author asks again.
     *
     * @throws StoryPermissionException
     */
    public function publish(User $actor, int $storyId, string $ipAddress = ''): Story
    {
        $story = $this->manageable($actor, $storyId);

        if ($story->state === PublicationState::Published) {
            return $story;
        }

        $trusted = $actor->can(Capability::PublishWithoutReview) || $actor->can(Capability::ModerateStories);

        if (!$trusted && $story->state === PublicationState::Pending) {
            return $story;
        }

        return $this->transition(
            $story,
            $trusted ? PublicationState::Published : PublicationState::Pending,
            $actor,
            $trusted ? 'story.publish' : 'story.submit',
            $ipAddress,
        );
    }

    /**
     * Takes a story away from readers. A story still waiting for approval goes back to being a draft.
     *
     * @throws StoryPermissionException
     */
    public function unpublish(User $actor, int $storyId, string $ipAddress = ''): Story
    {
        $story = $this->manageable($actor, $storyId);

        $target = match ($story->state) {
            PublicationState::Published => PublicationState::Unpublished,
            PublicationState::Pending => PublicationState::Draft,
            default => null,
        };

        return $target === null ? $story : $this->transition($story, $target, $actor, 'story.unpublish', $ipAddress);
    }

    /**
     * Removes the story and all its chapters together or not at all.
     *
     * @throws StoryPermissionException
     */
    public function delete(User $actor, int $storyId, string $ipAddress = ''): void
    {
        $story = $this->manageable($actor, $storyId);

        $this->database->transaction(function () use ($actor, $story, $ipAddress): void {
            $this->database->execute('DELETE FROM {prefix}chapters WHERE story_id = :id', ['id' => $story->id]);
            $this->database->execute('DELETE FROM {prefix}stories WHERE id = :id', ['id' => $story->id]);
            $this->audit->record($actor->id, 'story.delete', 'story', (string) $story->id, ['title' => $story->title, 'owner' => $story->ownerId], $ipAddress);
        });
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
        $base = Slug::fromText($values['title'], self::SLUG_BASE_MAX, 'story');

        for ($attempt = 0; $attempt < self::SLUG_ATTEMPTS; $attempt++) {
            // After losing a race for a slug, a random suffix avoids racing for the next number as well.
            $slug = $attempt === 0 ? $this->freeSlug($base) : $base . '-' . bin2hex(random_bytes(3));

            try {
                return (int) $this->database->insertGetId(
                    'INSERT INTO {prefix}stories (owner_id, title, slug, summary, notes, disclaimer, language, work_status,
                     publication_state, word_count, published_at, created_at, updated_at)
                     VALUES (:owner, :title, :slug, :summary, :notes, :disclaimer, :language, :work_status, :state, 0, NULL, :created, :updated)',
                    [
                        'owner' => $ownerId,
                        'title' => $values['title'],
                        'slug' => $slug,
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
            } catch (PDOException $exception) {
                if (($exception->errorInfo[1] ?? null) !== 1062) {
                    throw $exception;
                }
            }
        }

        // Five collisions in a row means something other than chance is wrong; do not loop forever.
        throw new PDOException('Could not find a free story address.');
    }

    private function freeSlug(string $base): string
    {
        for ($number = 1; $number < 1000; $number++) {
            $candidate = $number === 1 ? $base : $base . '-' . $number;

            if (!Slug::isReserved($candidate) && $this->findBySlug($candidate) === null) {
                return $candidate;
            }
        }

        return $base . '-' . bin2hex(random_bytes(4));
    }

    private function transition(Story $story, PublicationState $to, User $actor, string $auditAction, string $ipAddress): Story
    {
        $this->database->transaction(function () use ($story, $to, $actor, $auditAction, $ipAddress): void {
            $now = date('Y-m-d H:i:s');

            $this->database->execute(
                'UPDATE {prefix}stories SET publication_state = :state, published_at = :published, updated_at = :updated WHERE id = :id',
                [
                    'state' => $to->value,
                    // The first publication date is kept when a story is unpublished and published again.
                    'published' => $to === PublicationState::Published && $story->publishedAt === null ? $now : $story->publishedAt?->format('Y-m-d H:i:s'),
                    'updated' => $now,
                    'id' => $story->id,
                ],
            );
            $this->audit->record($actor->id, $auditAction, 'story', (string) $story->id, ['from' => $story->state->value, 'to' => $to->value], $ipAddress);
        });

        return $this->mustFind($story->id);
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
        );
    }
}
