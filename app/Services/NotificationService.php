<?php

/**
 * Each person's inbox of short messages about the stories they write or review.
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
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\Notification;
use LumoraFiction\Models\SiteAlert;
use LumoraFiction\Models\User;
use Throwable;

/**
 * Every read and change here is limited to one person's own rows, in the query itself, so another person's inbox can
 * never be listed or marked by passing its id. Creating a notification never throws: a problem with the inbox must not
 * undo the story change that caused it. Messages are written by the application and may name a story by its title, which
 * every screen escapes.
 */
final class NotificationService
{
    public const MESSAGE_MAX = 300;

    public const KEPT_PER_PERSON = 200;

    public const RETENTION_DAYS = 60;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @param string $path an admin address below /admin/, such as "review" or "stories/12"
     */
    public function notify(int $userId, string $kind, ?int $storyId, string $message, string $path): void
    {
        if ($userId < 1 || preg_match('/^[a-z0-9_]{1,30}\z/', $kind) !== 1 || !SiteAlert::validPath($path) || trim($message) === '') {
            error_log('[NotificationService] A notification with an invalid kind, path or message was dropped.');

            return;
        }

        try {
            $this->database->execute(
                'INSERT INTO {prefix}notifications (user_id, kind, story_id, message, path, created_at) VALUES (:user, :kind, :story, :message, :path, :created)',
                ['user' => $userId, 'kind' => $kind, 'story' => $storyId, 'message' => mb_substr(trim($message), 0, self::MESSAGE_MAX, 'UTF-8'), 'path' => $path, 'created' => date('Y-m-d H:i:s')],
            );
            $this->prune($userId);
        } catch (Throwable $exception) {
            error_log('[NotificationService] ' . $exception::class . ': ' . $exception->getMessage());
        }
    }

    /**
     * @return list<Notification> newest first
     */
    public function forUser(User $user, bool $unreadOnly = false, int $limit = 50, int $offset = 0): array
    {
        if (!$this->mayHaveInbox($user)) {
            return [];
        }

        return array_map(
            fn (array $row): Notification => $this->hydrate($row),
            $this->database->fetchAll(
                'SELECT * FROM {prefix}notifications WHERE user_id = :user' . ($unreadOnly ? ' AND read_at IS NULL' : '')
                . ' ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
                ['user' => $user->id],
            ),
        );
    }

    public function count(User $user, bool $unreadOnly = false): int
    {
        if (!$this->mayHaveInbox($user)) {
            return 0;
        }

        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM {prefix}notifications WHERE user_id = :user' . ($unreadOnly ? ' AND read_at IS NULL' : ''),
            ['user' => $user->id],
        );
    }

    public function countUnread(User $user): int
    {
        return $this->count($user, true);
    }

    /**
     * Marks one of the person's own notifications as read; another person's id changes nothing.
     */
    public function markRead(User $user, int $id): bool
    {
        if (!$this->mayHaveInbox($user)) {
            return false;
        }

        return $this->database->execute(
            'UPDATE {prefix}notifications SET read_at = :now WHERE id = :id AND user_id = :user AND read_at IS NULL',
            ['now' => date('Y-m-d H:i:s'), 'id' => $id, 'user' => $user->id],
        ) > 0;
    }

    public function markAllRead(User $user): int
    {
        if (!$this->mayHaveInbox($user)) {
            return 0;
        }

        return $this->database->execute(
            'UPDATE {prefix}notifications SET read_at = :now WHERE user_id = :user AND read_at IS NULL',
            ['now' => date('Y-m-d H:i:s'), 'user' => $user->id],
        );
    }

    /**
     * Marks everyone's notifications of one kind about one story as read, because the thing they asked for has happened.
     */
    public function resolveStory(string $kind, int $storyId): void
    {
        try {
            $this->database->execute(
                'UPDATE {prefix}notifications SET read_at = :now WHERE kind = :kind AND story_id = :story AND read_at IS NULL',
                ['now' => date('Y-m-d H:i:s'), 'kind' => $kind, 'story' => $storyId],
            );
        } catch (Throwable $exception) {
            error_log('[NotificationService] ' . $exception::class . ': ' . $exception->getMessage());
        }
    }

    /**
     * Removes what a story leaves behind when it is deleted for good.
     */
    public function forgetStory(int $storyId): void
    {
        $this->database->execute('DELETE FROM {prefix}notifications WHERE story_id = :story', ['story' => $storyId]);
    }

    /**
     * Notifications are for people who write or review stories. A suspended account holds no capability at all.
     */
    private function mayHaveInbox(User $user): bool
    {
        return $user->can(Capability::WriteStories);
    }

    /**
     * Drops the person's notifications past the retention period and beyond the number kept.
     */
    private function prune(int $userId): void
    {
        $this->database->execute(
            'DELETE FROM {prefix}notifications WHERE user_id = :user AND created_at < :cutoff',
            ['user' => $userId, 'cutoff' => date('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400)],
        );

        foreach (array_column($this->database->fetchAll(
            'SELECT id FROM {prefix}notifications WHERE user_id = :user ORDER BY id DESC LIMIT 500 OFFSET ' . self::KEPT_PER_PERSON,
            ['user' => $userId],
        ), 'id') as $old) {
            $this->database->execute('DELETE FROM {prefix}notifications WHERE id = :id', ['id' => (int) $old]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Notification
    {
        return new Notification(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            kind: (string) $row['kind'],
            storyId: $row['story_id'] === null ? null : (int) $row['story_id'],
            message: (string) $row['message'],
            path: (string) $row['path'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            readAt: is_string($row['read_at'] ?? null) ? new DateTimeImmutable($row['read_at']) : null,
        );
    }
}
