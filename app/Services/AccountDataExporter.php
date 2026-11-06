<?php

/**
 * Collects everything the archive holds about one account, for a data request.
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

use DateTimeImmutable;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Models\User;

/**
 * The export names fields one by one instead of dumping rows, so a column added later is not published by accident.
 * It never contains the password hash, a reset or confirmation token, or anything that identifies another account:
 * stories others wrote are named by title only, and administrator notes about the account are left out.
 */
final class AccountDataExporter
{
    /** The format of the export. Raise it when a field is renamed or removed. */
    public const FORMAT_VERSION = 1;

    /** A cap on the activity list, so an account with years of history cannot exhaust memory. */
    private const ACTIVITY_LIMIT = 5000;

    public function __construct(
        private readonly Database $database,
        private readonly ?CommentService $comments = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(User $subject): array
    {
        return [
            'format_version' => self::FORMAT_VERSION,
            'exported_at' => gmdate('c'),
            'account' => $this->account($subject),
            'stories' => $this->stories($subject->id),
            'bookmarks' => $this->bookmarks($subject->id),
            'reading_progress' => $this->progress($subject->id),
            'followed_stories' => $this->follows($subject->id),
            'notifications' => $this->notifications($subject->id),
            'comments' => $this->comments?->exportFor($subject->id) ?? [],
            'activity' => $this->activity($subject->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function account(User $subject): array
    {
        $row = $this->database->fetchOne(
            'SELECT editor_mode, publishing_override, reader_preferences, about, about_format, avatar_file, email_verified_at, password_changed_at, updated_at FROM {prefix}users WHERE id = :id',
            ['id' => $subject->id],
        ) ?? [];

        $preferences = json_decode((string) ($row['reader_preferences'] ?? ''), true);

        return [
            'display_name' => $subject->displayName,
            'login_name' => $subject->loginName,
            'email' => $subject->email,
            'role' => $subject->role->value,
            'status' => $subject->status->value,
            'registered_at' => $subject->createdAt->format('c'),
            'email_confirmed_at' => $this->date($row['email_verified_at'] ?? null),
            'password_changed_at' => $this->date($row['password_changed_at'] ?? null),
            'editor_mode' => $row['editor_mode'] ?? null,
            'publishing_override' => $row['publishing_override'] ?? null,
            'reading_preferences' => is_array($preferences) ? $preferences : [],
            'author_about' => $row['about'] ?? null,
            'author_about_format' => $row['about_format'] ?? null,
            'has_author_picture' => (string) ($row['avatar_file'] ?? '') !== '',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stories(int $userId): array
    {
        $stories = [];

        foreach ($this->database->fetchAll('SELECT * FROM {prefix}stories WHERE owner_id = :owner ORDER BY id', ['owner' => $userId]) as $story) {
            $chapters = [];

            foreach ($this->database->fetchAll('SELECT * FROM {prefix}chapters WHERE story_id = :story ORDER BY trashed_at IS NULL DESC, number, id', ['story' => (int) $story['id']]) as $chapter) {
                $chapters[] = [
                    'number' => (int) $chapter['number'],
                    'title' => (string) $chapter['title'],
                    'format' => (string) $chapter['content_format'],
                    'content' => (string) $chapter['content'],
                    'notes' => (string) $chapter['notes'],
                    'end_notes' => (string) ($chapter['end_notes'] ?? ''),
                    'state' => (string) $chapter['publication_state'],
                    'published_at' => $this->date($chapter['published_at']),
                    'updated_at' => $this->date($chapter['updated_at']),
                    'in_trash' => $chapter['trashed_at'] !== null,
                ];
            }

            $terms = $this->database->fetchAll(
                'SELECT t.type, t.name FROM {prefix}story_terms st JOIN {prefix}terms t ON t.id = st.term_id WHERE st.story_id = :story ORDER BY t.type, t.name',
                ['story' => (int) $story['id']],
            );

            $stories[] = [
                'title' => (string) $story['title'],
                'summary' => (string) $story['summary'],
                'notes' => (string) $story['notes'],
                'end_notes' => (string) ($story['end_notes'] ?? ''),
                'disclaimer' => (string) $story['disclaimer'],
                'language' => (string) $story['language'],
                'work_status' => (string) $story['work_status'],
                'state' => (string) $story['publication_state'],
                'created_at' => $this->date($story['created_at']),
                'published_at' => $this->date($story['published_at']),
                'updated_at' => $this->date($story['updated_at']),
                'in_trash' => $story['trashed_at'] !== null,
                'classification' => array_map(static fn (array $term): array => ['type' => (string) $term['type'], 'name' => (string) $term['name']], $terms),
                'chapters' => $chapters,
            ];
        }

        return $stories;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bookmarks(int $userId): array
    {
        return array_map(fn (array $row): array => [
            'story' => (string) $row['title'],
            'chapter' => $row['number'] === null ? null : (int) $row['number'],
            'saved_at' => $this->date($row['created_at']),
        ], $this->database->fetchAll(
            'SELECT s.title, c.number, b.created_at FROM {prefix}bookmarks b
             JOIN {prefix}stories s ON s.id = b.story_id LEFT JOIN {prefix}chapters c ON c.id = b.chapter_id
             WHERE b.user_id = :user ORDER BY b.created_at, b.id',
            ['user' => $userId],
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function progress(int $userId): array
    {
        return array_map(fn (array $row): array => [
            'story' => (string) $row['title'],
            'last_chapter' => $row['number'] === null ? null : (int) $row['number'],
            'position' => (int) $row['position'],
            'updated_at' => $this->date($row['updated_at']),
        ], $this->database->fetchAll(
            'SELECT s.title, c.number, p.position, p.updated_at FROM {prefix}reading_progress p
             JOIN {prefix}stories s ON s.id = p.story_id LEFT JOIN {prefix}chapters c ON c.id = p.chapter_id
             WHERE p.user_id = :user ORDER BY p.updated_at',
            ['user' => $userId],
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function follows(int $userId): array
    {
        return array_map(fn (array $row): array => ['story' => (string) $row['title'], 'followed_at' => $this->date($row['created_at'])], $this->database->fetchAll(
            'SELECT s.title, f.created_at FROM {prefix}story_follows f JOIN {prefix}stories s ON s.id = f.story_id WHERE f.user_id = :user ORDER BY f.created_at',
            ['user' => $userId],
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notifications(int $userId): array
    {
        return array_map(fn (array $row): array => [
            'message' => (string) $row['message'],
            'created_at' => $this->date($row['created_at']),
            'read_at' => $this->date($row['read_at']),
        ], $this->database->fetchAll('SELECT message, created_at, read_at FROM {prefix}notifications WHERE user_id = :user ORDER BY id', ['user' => $userId]));
    }

    /**
     * What the audit log recorded this account doing. The details column is left out because it can name other accounts.
     *
     * @return list<array<string, mixed>>
     */
    private function activity(int $userId): array
    {
        return array_map(fn (array $row): array => [
            'action' => (string) $row['action'],
            'when' => $this->date($row['created_at']),
            'ip_address' => $row['ip_address'],
        ], $this->database->fetchAll(
            'SELECT action, created_at, ip_address FROM {prefix}audit_log WHERE user_id = :user ORDER BY id DESC LIMIT ' . self::ACTIVITY_LIMIT,
            ['user' => $userId],
        ));
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? (new DateTimeImmutable($value))->format('c') : null;
    }
}
