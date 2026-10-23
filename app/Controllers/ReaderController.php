<?php

/**
 * A signed-in reader's bookmarks: the list, adding one and removing one.
 *
 * @package LumoraFiction
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers;

use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Models\FollowOutcome;
use LumoraFiction\Models\Story;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\BookmarkPresenter;
use LumoraFiction\Services\BookmarkService;
use LumoraFiction\Services\FollowPresenter;
use LumoraFiction\Services\PublicArchive;
use LumoraFiction\Services\StoryFollowService;
use LumoraFiction\Services\StoryPresenter;
use LumoraFiction\Services\StoryService;
use LumoraFiction\Services\UserService;

/**
 * Every page is private to one account: it is never cached, never indexed, and answers a visitor who is not signed
 * in with the sign-in page. The address of a bookmark's story is always rebuilt from the database, never taken from
 * the request, so a form cannot be used to send someone to another site.
 */
final class ReaderController
{
    private const EXPIRED = 'Your session expired. Please try again.';

    public function __construct(
        private readonly ThemeRenderer $themes,
        private readonly AuthService $auth,
        private readonly BookmarkService $bookmarks,
        private readonly BookmarkPresenter $bookmarkPresenter,
        private readonly StoryService $stories,
        private readonly StoryPresenter $presenter,
        private readonly UserService $users,
        private readonly FlashMessages $flash,
        private readonly StoryFollowService $follows,
        private readonly FollowPresenter $followPresenter,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function list(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        $total = $this->bookmarks->countVisible($user);
        $pages = max(1, (int) ceil($total / PublicArchive::PAGE_SIZE));
        $requested = $request->queryValue('page');
        $page = $requested === null ? 1 : (preg_match('/^[1-9][0-9]{0,8}\z/', $requested) === 1 ? (int) $requested : 0);

        if ($page < 1 || $page > $pages) {
            return $this->themes->notFound()->withHeader('Cache-Control', 'private, no-store');
        }

        $entries = $this->bookmarks->listFor($user, PublicArchive::PAGE_SIZE, ($page - 1) * PublicArchive::PAGE_SIZE);
        $profiles = $this->users->publicProfiles(array_map(static fn (array $entry): int => $entry['story']->ownerId, $entries));
        $cards = [];
        $followed = $this->follows->followedAmong($user, array_map(static fn (array $entry): int => $entry['story']->id, $entries));

        foreach ($entries as $entry) {
            $story = $entry['story'];
            $profile = $profiles[$story->ownerId] ?? ['display_name' => '', 'slug' => ''];
            $card = $this->presenter->card($story, $profile['display_name'], $profile['slug'] === '' ? '' : PublicUrl::author($profile['slug']), PublicUrl::story($story->slug));

            // A bookmark on one chapter opens that chapter; the story's own title still leads to the story.
            $cards[] = $card + [
                'chapter_title' => $entry['chapter_title'] ?? '',
                'chapter_url' => $entry['chapter_number'] === null ? '' : PublicUrl::chapter($story->slug, $entry['chapter_number']),
                'remove' => $this->bookmarkPresenter->removeForm($entry['id']),
                'follow' => $this->followPresenter->button($story->id, in_array($story->id, $followed, true), 'bookmarks'),
            ];
        }

        $notices = [];

        foreach ($this->flash->pull($request->cookies) as $message) {
            $notices[] = ['type' => $message['type'], 'text' => $message['message']];
        }

        return $this->themes->render('bookmarks', [
            'title' => 'My bookmarks',
            'description' => '',
            'total' => number_format($total),
            'stories' => $cards,
            'notices' => $notices,
            'pagination' => [
                'page' => $page,
                'pages' => $pages,
                'url' => PublicUrl::account('bookmarks') . '?page={page}',
            ],
        ], 200, ['title' => 'My bookmarks', 'noindex' => true])->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @param array<string, string> $params
     */
    public function add(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        if (!$this->validToken($request)) {
            $this->flash->add('error', self::EXPIRED);

            return $this->back($request);
        }

        $storyId = $this->whole($request->input('story_id'));
        $number = $this->whole($request->input('chapter_number'));

        if ($storyId === null) {
            $this->flash->add('error', 'That story or chapter is not available.');

            return Response::redirect(PublicUrl::account('bookmarks'), 303);
        }

        $outcome = $this->bookmarks->add($user, $storyId, $number);
        $this->flash->add($outcome->succeeded() ? 'success' : 'error', $outcome->message());

        // The tick box on the bookmark form: a bookmark that could not be made follows nothing.
        if ($outcome->succeeded() && $request->input('follow') === '1') {
            $followed = $this->follows->follow($user, $storyId);

            if ($followed === FollowOutcome::Followed || !$followed->succeeded()) {
                $this->flash->add($followed->succeeded() ? 'success' : 'error', $followed->message());
            }
        }

        return $this->back($request);
    }

    /**
     * @param array<string, string> $params
     */
    public function remove(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        if (!$this->validToken($request)) {
            $this->flash->add('error', self::EXPIRED);

            return $this->back($request);
        }

        $id = $this->whole($request->input('bookmark_id'));

        // Someone else's id removes nothing, and the answer is the same words either way.
        if ($id !== null) {
            $this->bookmarks->remove($user, $id);
        }

        $this->flash->add('success', 'Removed from your bookmarks.');

        return $this->back($request);
    }

    /**
     * Back to the story or chapter the button was on, rebuilt from the database, or else to the bookmarks list.
     */
    private function back(Request $request): Response
    {
        $storyId = $this->whole($request->input('story_id'));
        $story = $storyId === null ? null : $this->publicStory($storyId);

        if ($story === null) {
            return Response::redirect(PublicUrl::account('bookmarks'), 303);
        }

        $number = $this->whole($request->input('chapter_number'));

        return Response::redirect($number === null ? PublicUrl::story($story->slug) : PublicUrl::chapter($story->slug, $number), 303);
    }

    private function publicStory(int $id): ?Story
    {
        $story = $this->stories->find($id);

        return $story === null ? null : $this->stories->findPublicBySlug($story->slug);
    }

    /**
     * A whole number of at most nine digits, or null for anything else, including an empty value.
     */
    private function whole(?string $value): ?int
    {
        return $value !== null && preg_match('/^[1-9][0-9]{0,8}\z/', $value) === 1 ? (int) $value : null;
    }

    private function mustSignIn(): Response
    {
        $this->flash->add('info', 'Please sign in to use bookmarks.');

        return Response::redirect(PublicUrl::account('sign-in'), 302)->withHeader('Cache-Control', 'private, no-store');
    }

    private function validToken(Request $request): bool
    {
        return Csrf::verify(BookmarkPresenter::ACTION, $request->input('csrf_token'), $request->cookies);
    }
}
