<?php

/**
 * Tells a story's writer about a new published comment, and tells readers who asked when the writer answers.
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

use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Mail\Mailer;
use LumoraFiction\Models\Comment;
use LumoraFiction\Models\CommentStatus;
use LumoraFiction\Models\User;

/**
 * Listens to the comment actions, which run after the change is saved, so nothing here can undo a comment. Only a published
 * comment ever notifies anyone: a held, hidden or spam comment sends nothing, and a comment that is approved later notifies
 * then. Messages name the commenter and the chapter and never carry the comment's text.
 */
final class CommentNotifier
{
    public const KIND = 'comment';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly CommentService $comments,
        private readonly Database $database,
        private readonly Mailer $mailer,
        private readonly string $siteUrl,
        private readonly string $siteName,
    ) {
    }

    public function register(HookManager $hooks): void
    {
        $hooks->addAction('comment_created', $this->created(...));
        $hooks->addAction('comment_status_changed', $this->statusChanged(...));
    }

    public function created(mixed $comment, mixed $author = null): void
    {
        if ($comment instanceof Comment && $comment->status === CommentStatus::Published) {
            $this->announce($comment);
        }
    }

    public function statusChanged(mixed $comment, mixed $from = null, mixed $actor = null): void
    {
        // Approving a held comment is the moment it appears, so it is the moment the writer hears about it.
        if ($comment instanceof Comment && $comment->status === CommentStatus::Published && $from === CommentStatus::Pending) {
            $this->announce($comment);
        }
    }

    private function announce(Comment $comment): void
    {
        $row = $this->database->fetchOne(
            'SELECT s.owner_id, s.title AS story_title, s.slug, ch.number FROM {prefix}stories s JOIN {prefix}chapters ch ON ch.id = :chapter WHERE s.id = :story',
            ['chapter' => $comment->chapterId, 'story' => $comment->storyId],
        );

        if ($row === null) {
            return;
        }

        $ownerId = (int) $row['owner_id'];
        $name = (string) ($this->database->fetchColumn('SELECT display_name FROM {prefix}users WHERE id = :id', ['id' => $comment->userId]) ?? '');
        $where = '“' . $row['story_title'] . '”, chapter ' . (int) $row['number'];
        $url = $this->absolute(PublicUrl::chapter((string) $row['slug'], (int) $row['number']) . '#comment-' . $comment->id);

        $parent = $comment->parentId === null ? null : $this->comments->find($comment->parentId);

        if ($parent !== null) {
            $this->announceReply($comment, $parent, $ownerId, $name, $where, $url);

            return;
        }

        if ($comment->userId === $ownerId) {
            $this->tellCommenters($comment, $ownerId, $name, $where, $url);

            return;
        }

        $preferences = $this->comments->preferences($ownerId);

        if ($preferences['notify_comments']) {
            $this->notifications->notify($ownerId, self::KIND, $comment->storyId, ($name === '' ? 'Someone' : $name) . ' commented on ' . $where . '.', 'comments');
        }

        if ($preferences['email_comments']) {
            $this->email($ownerId, 'New comment on ' . $where, ($name === '' ? 'Someone' : $name) . ' commented on ' . $where . ".\n\nRead it here: " . $url . "\n\nYou can change these emails on your profile page.");
        }
    }

    /**
     * A published reply tells the story's writer, as a comment does, and the person whose comment it answers. Nobody is told
     * about their own reply, and when the writer is the one replied to they hear it once, as the person replied to.
     */
    private function announceReply(Comment $reply, Comment $parent, int $ownerId, string $name, string $where, string $url): void
    {
        $who = $name === '' ? 'Someone' : $name;

        if ($reply->userId !== $ownerId && $parent->userId !== $ownerId) {
            $preferences = $this->comments->preferences($ownerId);

            if ($preferences['notify_comments']) {
                $this->notifications->notify($ownerId, self::KIND, $reply->storyId, $who . ' replied to a comment on ' . $where . '.', 'comments');
            }

            if ($preferences['email_comments']) {
                $this->email($ownerId, 'New reply on ' . $where, $who . ' replied to a comment on ' . $where . ".\n\nRead it here: " . $url . "\n\nYou can change these emails on your profile page.");
            }
        }

        if ($parent->userId === $reply->userId) {
            return;
        }

        $preferences = $this->comments->preferences($parent->userId);

        if ($preferences['notify_comments']) {
            $this->notifications->notify($parent->userId, self::KIND, $reply->storyId, $who . ' replied to your comment on ' . $where . '.', 'comments');
        }

        if ($preferences['email_replies']) {
            $this->email($parent->userId, $who . ' replied to your comment on ' . $where, $who . ' replied to your comment on ' . $where . ".\n\nRead it here: " . $url . "\n\nYou can stop these emails on your profile page.");
        }
    }

    /**
     * The writer answered under their own story: readers who commented there and asked to be told are emailed, a few at most.
     */
    private function tellCommenters(Comment $comment, int $ownerId, string $writer, string $where, string $url): void
    {
        foreach ($this->comments->replyRecipients($comment->chapterId, $ownerId) as $person) {
            $this->email($person['id'], ($writer === '' ? 'The author' : $writer) . ' replied on ' . $where, ($writer === '' ? 'The author' : $writer) . ' commented on ' . $where . ", a chapter you commented on.\n\nRead it here: " . $url . "\n\nYou can stop these emails on your profile page.");
        }
    }

    private function email(int $userId, string $subject, string $body): void
    {
        $row = $this->database->fetchOne("SELECT email FROM {prefix}users WHERE id = :id AND status = 'active'", ['id' => $userId]);

        if ($row === null || !is_string($row['email']) || $row['email'] === '') {
            return;
        }

        // A mail problem must never undo the comment that caused it.
        try {
            $this->mailer->send($row['email'], '[' . $this->siteName . '] ' . $subject, $body);
        } catch (\Throwable $exception) {
            error_log('[CommentNotifier] ' . $exception::class . ': ' . $exception->getMessage());
        }
    }

    private function absolute(string $path): string
    {
        return rtrim($this->siteUrl, '/') . '/' . ltrim(preg_replace('#^' . preg_quote(\LumoraFiction\Core\Http\BasePath::get(), '#') . '#', '', $path) ?? $path, '/');
    }
}
