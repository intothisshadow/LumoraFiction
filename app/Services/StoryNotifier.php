<?php

/**
 * Turns story state changes into notifications for the people who need to know.
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

use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\User;
use LumoraFiction\Models\UserRole;

/**
 * Listens to the story_state_changed action, which runs after the change is saved, so nothing here can undo it. Staff are
 * told when a story is waiting for review and the writer is told what became of it; nobody is told about what they did
 * themselves. Messages carry no reason a reviewer wrote: that stays on the story form, where the writer reads it.
 */
final class StoryNotifier
{
    public const REVIEW_WAITING = 'review_waiting';

    public const OUTCOME = 'outcome';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly Database $database,
    ) {
    }

    public function register(HookManager $hooks): void
    {
        $hooks->addAction('story_state_changed', $this->stateChanged(...));
        $hooks->addAction('story_deleted', $this->deleted(...));
        $hooks->addAction('story_trashed', $this->trashed(...));
    }

    /**
     * A story that leaves the queue by being trashed no longer waits for anyone.
     */
    public function trashed(mixed $storyId): void
    {
        if (is_int($storyId)) {
            $this->notifications->resolveStory(self::REVIEW_WAITING, $storyId);
        }
    }

    public function stateChanged(mixed $story, mixed $from, mixed $actor): void
    {
        if (!$story instanceof Story || !$from instanceof PublicationState || !$actor instanceof User) {
            return;
        }

        $to = $story->state;

        if ($to === PublicationState::Pending && $from !== PublicationState::Pending) {
            $this->tellStaffItIsWaiting($story, $actor);

            return;
        }

        if ($from === PublicationState::Pending) {
            // Whatever the decision was, the request for one is answered.
            $this->notifications->resolveStory(self::REVIEW_WAITING, $story->id);
        }

        if ($actor->id === $story->ownerId) {
            return;
        }

        $title = $this->quote($story->title);

        $message = match (true) {
            $from === PublicationState::Pending && $to === PublicationState::Published => $title . ' was approved and is now published.',
            $from === PublicationState::Pending && $to === PublicationState::Draft => $title . ' was sent back. Open it to read why.',
            $from === PublicationState::Published && $to === PublicationState::Unpublished => $title . ' was unpublished by a member of staff.',
            default => null,
        };

        if ($message !== null) {
            $this->notifications->notify($story->ownerId, self::OUTCOME, $story->id, $message, 'stories/' . $story->id);
        }
    }

    public function deleted(mixed $storyId): void
    {
        if (is_int($storyId)) {
            $this->notifications->forgetStory($storyId);
        }
    }

    private function tellStaffItIsWaiting(Story $story, User $actor): void
    {
        $name = (string) $this->database->fetchColumn('SELECT display_name FROM {prefix}users WHERE id = :id', ['id' => $story->ownerId]);
        $message = $this->quote($story->title) . ($name === '' ? '' : ' by ' . $name) . ' is waiting for review.';

        // Anyone who may review, apart from the person who just submitted it.
        $staff = $this->database->fetchAll(
            "SELECT id FROM {prefix}users WHERE role IN (:administrator, :editor) AND status = 'active' AND id <> :actor",
            ['administrator' => UserRole::Administrator->value, 'editor' => UserRole::Editor->value, 'actor' => $actor->id],
        );

        foreach ($staff as $row) {
            $this->notifications->notify((int) $row['id'], self::REVIEW_WAITING, $story->id, $message, 'review');
        }
    }

    private function quote(string $title): string
    {
        return '“' . mb_substr($title, 0, 120, 'UTF-8') . '”';
    }
}
