<?php

/**
 * A signed-in reader's followed stories: following and stopping, and the Updated stories page.
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
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\FollowPresenter;
use LumoraFiction\Services\PublicArchive;
use LumoraFiction\Services\StoryFollowService;
use LumoraFiction\Services\StoryPresenter;
use LumoraFiction\Services\StoryService;
use LumoraFiction\Services\UserService;

/**
 * Every page is private to one account: never cached, never indexed, and a visitor who is not signed in is sent to
 * the sign-in page. Where a form returns to is chosen from a short list and rebuilt from the database, never taken
 * from the request, and every change touches only the signed-in reader's own rows.
 */
final class FollowController
{
    private const EXPIRED = 'Your session expired. Please try again.';

    public function __construct(
        private readonly ThemeRenderer $themes,
        private readonly AuthService $auth,
        private readonly StoryFollowService $follows,
        private readonly FollowPresenter $presenter,
        private readonly StoryService $stories,
        private readonly StoryPresenter $storyPresenter,
        private readonly UserService $users,
        private readonly FlashMessages $flash,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function updates(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        $total = $this->follows->countUpdates($user);
        $pages = max(1, (int) ceil($total / PublicArchive::PAGE_SIZE));
        $requested = $request->queryValue('page');
        $page = $requested === null ? 1 : (preg_match('/^[1-9][0-9]{0,8}\z/', $requested) === 1 ? (int) $requested : 0);

        if ($page < 1 || $page > $pages) {
            return $this->themes->notFound()->withHeader('Cache-Control', 'private, no-store');
        }

        $entries = $this->follows->updatesFor($user, PublicArchive::PAGE_SIZE, ($page - 1) * PublicArchive::PAGE_SIZE);
        $profiles = $this->users->publicProfiles(array_map(static fn (array $entry): int => $entry['story']->ownerId, $entries));
        $cards = [];

        foreach ($entries as $entry) {
            $story = $entry['story'];
            $profile = $profiles[$story->ownerId] ?? ['display_name' => '', 'slug' => ''];
            $card = $this->storyPresenter->card($story, $profile['display_name'], $profile['slug'] === '' ? '' : PublicUrl::author($profile['slug']), PublicUrl::story($story->slug));

            $cards[] = $card + [
                'whats_new' => self::describe($entry['new'], $entry['revised']),
                'new_url' => $entry['first_new'] === null ? '' : PublicUrl::chapter($story->slug, $entry['first_new']),
                'stop' => $this->presenter->stopForm($story->id),
            ];
        }

        return $this->themes->render('updates', [
            'title' => 'Updated stories',
            'description' => '',
            'total' => number_format($total),
            'stories' => $cards,
            'notices' => $this->notices($request),
            'stop_all' => $this->follows->count($user) === 0 ? [] : ['url' => PublicUrl::account('updates/stop-all'), 'label' => 'Stop tracking all'],
            'pagination' => [
                'page' => $page,
                'pages' => $pages,
                'url' => PublicUrl::account('updates') . '?page={page}',
            ],
        ], 200, ['title' => 'Updated stories', 'noindex' => true])->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @param array<string, string> $params
     */
    public function follow(array $params, Request $request): Response
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
        $outcome = $storyId === null ? FollowOutcome::NotAvailable : $this->follows->follow($user, $storyId);
        $this->flash->add($outcome->succeeded() ? 'success' : 'error', $outcome->message());

        return $this->back($request);
    }

    /**
     * @param array<string, string> $params
     */
    public function stop(array $params, Request $request): Response
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

        // Someone else's story, or one that was never followed, changes nothing and gets the same words.
        if ($storyId !== null) {
            $this->follows->stop($user, $storyId);
        }

        $this->flash->add('success', 'You are no longer following that story. Your place and bookmarks are kept.');

        return $this->back($request);
    }

    /**
     * The page that asks before every story is dropped.
     *
     * @param array<string, string> $params
     */
    public function confirmStopAll(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        $form = [
            'id' => FollowPresenter::ACTION,
            'action' => PublicUrl::account('updates/stop-all'),
            'csrf_token' => Csrf::token(FollowPresenter::ACTION),
            'submit' => 'Stop tracking all',
            'heading' => '',
            'fields' => [],
            'errors' => [],
        ];

        return $this->themes->render('account', [
            'intro' => 'This stops following every story, so none of them raises a notice any more. Your reading places and bookmarks are not touched, and you can follow a story again from its page.',
            'notices' => $this->notices($request),
            'forms' => [$form],
            'links' => [['label' => 'Keep following', 'url' => PublicUrl::account('updates')]],
            'shortcuts' => [],
        ], 200, ['title' => 'Stop tracking all stories', 'noindex' => true, 'variant' => 'confirm'])->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @param array<string, string> $params
     */
    public function stopAll(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        if (!$this->validToken($request)) {
            $this->flash->add('error', self::EXPIRED);

            return Response::redirect(PublicUrl::account('updates'), 303);
        }

        $this->follows->stopAll($user);
        $this->flash->add('success', 'You are not following any stories now.');

        return Response::redirect(PublicUrl::account('updates'), 303);
    }

    /**
     * The words under a story on the Updated stories page.
     */
    public static function describe(int $new, bool $revised): string
    {
        $parts = [];

        if ($new > 0) {
            $parts[] = $new === 1 ? '1 new chapter' : number_format($new) . ' new chapters';
        }

        if ($revised) {
            $parts[] = 'a chapter you read was revised';
        }

        return ucfirst(implode(' and ', $parts));
    }

    /**
     * Back to the page the form was on: the story (rebuilt from the database), the bookmarks or the Updated stories.
     */
    private function back(Request $request): Response
    {
        $from = $request->input('from');
        $storyId = $this->whole($request->input('story_id'));

        if ($from === 'story' && $storyId !== null) {
            $found = $this->stories->find($storyId);
            $story = $found === null ? null : $this->stories->findPublicBySlug($found->slug);

            if ($story !== null) {
                return Response::redirect(PublicUrl::story($story->slug), 303);
            }
        }

        return Response::redirect($from === 'bookmarks' ? PublicUrl::account('bookmarks') : PublicUrl::account('updates'), 303);
    }

    /**
     * @return list<array{type: string, text: string}>
     */
    private function notices(Request $request): array
    {
        $notices = [];

        foreach ($this->flash->pull($request->cookies) as $message) {
            $notices[] = ['type' => $message['type'], 'text' => $message['message']];
        }

        return $notices;
    }

    private function whole(?string $value): ?int
    {
        return $value !== null && preg_match('/^[1-9][0-9]{0,8}\z/', $value) === 1 ? (int) $value : null;
    }

    private function mustSignIn(): Response
    {
        $this->flash->add('info', 'Please sign in to follow stories.');

        return Response::redirect(PublicUrl::account('sign-in'), 302)->withHeader('Cache-Control', 'private, no-store');
    }

    private function validToken(Request $request): bool
    {
        return Csrf::verify(FollowPresenter::ACTION, $request->input('csrf_token'), $request->cookies);
    }
}
