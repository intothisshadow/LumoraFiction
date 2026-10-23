<?php

/**
 * Builds the bookmark buttons and forms a theme draws on a story, chapter or bookmarks page.
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
 * A theme receives each button as one bundled array: where to post, the token, the hidden values and the words on the
 * button. It never learns who is signed in, and a guest or an account without the permission simply gets no buttons.
 */
final class BookmarkPresenter
{
    /** The token action shared by the add and remove forms. */
    public const ACTION = 'bookmark';

    public function __construct(
        private readonly BookmarkService $bookmarks,
    ) {
    }

    /**
     * The buttons for a story page (one for the story) or a chapter page (one for the story and one for the chapter).
     *
     * @param array{name: string, label: string}|array{} $option an extra tick box for the forms that add a bookmark
     * @return list<array{scope: string, saved: bool, label: string, form: array<string, mixed>}>
     */
    public function buttons(?User $user, int $storyId, ?int $chapterNumber, ?int $chapterId, array $option = []): array
    {
        if ($user === null || !$user->can(Capability::Bookmark)) {
            return [];
        }

        $saved = $this->bookmarks->forStory($user, $storyId);
        $buttons = [$this->button('story', 'story', $saved[0] ?? null, $storyId, null, $option)];

        if ($chapterNumber !== null && $chapterId !== null) {
            $buttons[] = $this->button('chapter', 'chapter', $saved[$chapterId] ?? null, $storyId, $chapterNumber, $option);
        }

        return $buttons;
    }

    /**
     * The form that removes one bookmark from the bookmarks page.
     *
     * @return array<string, mixed>
     */
    public function removeForm(int $bookmarkId): array
    {
        return $this->form(PublicUrl::account('bookmarks/remove'), 'Remove', ['bookmark_id' => (string) $bookmarkId]);
    }

    /**
     * @param array{name: string, label: string}|array{} $option
     * @return array{scope: string, saved: bool, label: string, form: array<string, mixed>}
     */
    private function button(string $scope, string $noun, ?int $bookmarkId, int $storyId, ?int $chapterNumber, array $option): array
    {
        $place = ['story_id' => (string) $storyId] + ($chapterNumber === null ? [] : ['chapter_number' => (string) $chapterNumber]);

        if ($bookmarkId !== null) {
            return [
                'scope' => $scope,
                'saved' => true,
                'label' => 'Remove ' . $noun . ' bookmark',
                'form' => $this->form(PublicUrl::account('bookmarks/remove'), 'Remove ' . $noun . ' bookmark', ['bookmark_id' => (string) $bookmarkId] + $place),
            ];
        }

        return [
            'scope' => $scope,
            'saved' => false,
            'label' => 'Bookmark this ' . $noun,
            'form' => $this->form(PublicUrl::account('bookmarks/add'), 'Bookmark this ' . $noun, $place) + ($option === [] ? [] : ['option' => $option]),
        ];
    }

    /**
     * @param array<string, string> $hidden
     * @return array<string, mixed>
     */
    private function form(string $action, string $submit, array $hidden): array
    {
        return [
            'action' => $action,
            'csrf_token' => Csrf::token(self::ACTION),
            'submit' => $submit,
            'hidden' => $hidden,
        ];
    }
}
