<?php

/**
 * Builds the follow buttons, forms and notices a theme draws on the story, bookmarks and Updated stories pages.
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

use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;

/**
 * Like the bookmark buttons, each form reaches a theme as one bundled array and a guest simply gets nothing. The
 * "from" value says which page to return to; the controller maps it to an address of its own, so a form can never be
 * used to send someone to another site.
 */
final class FollowPresenter
{
    /** The token action shared by every follow form. */
    public const ACTION = 'story_follow';

    /**
     * The Follow this story or Stop following button for a story page, as a list of one so a theme loops over it like
     * the bookmark buttons, or an empty list for a guest.
     *
     * @return list<array{following: bool, label: string, form: array<string, mixed>}>
     */
    public function storyButton(?User $user, int $storyId, bool $following): array
    {
        if ($user === null || !$user->can(Capability::Bookmark)) {
            return [];
        }

        return [$this->button($storyId, $following, 'story')];
    }

    /**
     * The same button for another page, where the reader is already known to be allowed.
     *
     * @return array{following: bool, label: string, form: array<string, mixed>}
     */
    public function button(int $storyId, bool $following, string $from): array
    {
        $label = $following ? 'Stop following' : 'Follow this story';

        return [
            'following' => $following,
            'label' => $label,
            'form' => $this->form(PublicUrl::account($following ? 'updates/stop' : 'updates/follow'), $label, ['story_id' => (string) $storyId, 'from' => $from]),
        ];
    }

    /**
     * The Stop tracking button on one entry of the Updated stories page.
     *
     * @return array<string, mixed>
     */
    public function stopForm(int $storyId): array
    {
        return $this->form(PublicUrl::account('updates/stop'), 'Stop tracking', ['story_id' => (string) $storyId, 'from' => 'updates']);
    }

    /**
     * The tick box added to a bookmark button's form.
     *
     * @return array{name: string, label: string}
     */
    public function bookmarkOption(): array
    {
        return ['name' => 'follow', 'label' => 'Also tell me when this story is updated'];
    }

    /**
     * @param array<string, string> $hidden
     * @return array<string, mixed>
     */
    private function form(string $action, string $submit, array $hidden): array
    {
        return ['action' => $action, 'csrf_token' => Csrf::token(self::ACTION), 'submit' => $submit, 'hidden' => $hidden];
    }
}
