<?php

/**
 * A signed-in reader's Continue reading page: the stories they have started, forgetting one and clearing the list.
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
use LumoraFiction\Core\Theme\ReadingPositionScript;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\PublicArchive;
use LumoraFiction\Services\ReaderPreferences;
use LumoraFiction\Services\ReadingProgressService;
use LumoraFiction\Services\StoryFollowService;
use LumoraFiction\Services\StoryPresenter;
use LumoraFiction\Services\UserService;

/**
 * Every page is private to one account: never cached, never indexed, and a visitor who is not signed in is sent to
 * the sign-in page. Forgetting touches only the signed-in reader's own rows, and every address shown is rebuilt from
 * the database.
 */
final class ReadingListController
{
    /** The token action shared by the forms on these pages. */
    public const ACTION = 'reading_list';

    private const EXPIRED = 'Your session expired. Please try again.';

    public function __construct(
        private readonly ThemeRenderer $themes,
        private readonly AuthService $auth,
        private readonly ReadingProgressService $progress,
        private readonly StoryPresenter $presenter,
        private readonly UserService $users,
        private readonly FlashMessages $flash,
        private readonly StoryFollowService $follows,
        private readonly ReaderPreferences $preferences,
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

        $total = $this->progress->countFor($user);
        $pages = max(1, (int) ceil($total / PublicArchive::PAGE_SIZE));
        $requested = $request->queryValue('page');
        $page = $requested === null ? 1 : (preg_match('/^[1-9][0-9]{0,8}\z/', $requested) === 1 ? (int) $requested : 0);

        if ($page < 1 || $page > $pages) {
            return $this->themes->notFound()->withHeader('Cache-Control', 'private, no-store');
        }

        $entries = $this->progress->listFor($user, PublicArchive::PAGE_SIZE, ($page - 1) * PublicArchive::PAGE_SIZE);
        $profiles = $this->users->publicProfiles(array_map(static fn (array $entry): int => $entry['story']->ownerId, $entries));
        $cards = [];

        // Only followed stories can have something new, and only for a reader who asked to be told.
        $changes = $this->preferences->wantsUpdates($user->id)
            ? $this->follows->changes($user, array_map(static fn (array $entry): int => $entry['story']->id, $entries))
            : [];

        foreach ($entries as $entry) {
            $story = $entry['story'];
            $profile = $profiles[$story->ownerId] ?? ['display_name' => '', 'slug' => ''];
            $card = $this->presenter->card($story, $profile['display_name'], $profile['slug'] === '' ? '' : PublicUrl::author($profile['slug']), PublicUrl::story($story->slug));
            $change = $changes[$story->id] ?? null;

            $cards[] = $card + [
                'whats_new' => $change === null ? '' : FollowController::describe($change['new'], $change['revised']),
                'chapter_title' => $entry['title'] !== '' ? $entry['title'] : 'Chapter ' . $entry['number'],
                'chapter_url' => PublicUrl::chapter($story->slug, $entry['number']),
                'read_on' => date('j F Y', (int) strtotime($entry['read_at'])),
                'finished' => $entry['finished'],
                'progress' => $entry['finished'] ? '' : $this->share($entry['position']),
                'forget' => $this->form(PublicUrl::account('continue/forget'), 'Forget', ['story_id' => (string) $story->id]),
            ];
        }

        return $this->themes->render('continue', [
            'title' => 'Continue reading',
            'description' => '',
            'total' => number_format($total),
            'stories' => $cards,
            'notices' => $this->notices($request),
            'clear' => $total === 0 ? [] : ['url' => PublicUrl::account('continue/clear'), 'label' => 'Clear the list'],
            'pagination' => [
                'page' => $page,
                'pages' => $pages,
                'url' => PublicUrl::account('continue') . '?page={page}',
            ],
        ], 200, ['title' => 'Continue reading', 'noindex' => true])->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @param array<string, string> $params
     */
    public function forget(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        if (!$this->validToken($request)) {
            $this->flash->add('error', self::EXPIRED);

            return Response::redirect(PublicUrl::account('continue'), 303);
        }

        $id = $this->whole($request->input('story_id'));

        // Someone else's id removes nothing, and the answer is the same words either way.
        if ($id !== null) {
            $this->progress->forget($user, $id);
        }

        $this->flash->add('success', 'Removed from your reading list.');

        return Response::redirect(PublicUrl::account('continue'), 303);
    }

    /**
     * The page that asks before the whole list is cleared.
     *
     * @param array<string, string> $params
     */
    public function confirmClear(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        $form = [
            'id' => self::ACTION,
            'action' => PublicUrl::account('continue/clear'),
            'csrf_token' => Csrf::token(self::ACTION),
            'submit' => 'Clear the list',
            'heading' => '',
            'fields' => [],
            'errors' => [],
        ];

        return $this->themes->render('account', [
            'intro' => 'This forgets every story you have started and where you stopped reading. Your bookmarks are not touched.',
            'notices' => $this->notices($request),
            'forms' => [$form],
            'links' => [['label' => 'Keep my list', 'url' => PublicUrl::account('continue')]],
            'shortcuts' => [],
        ], 200, ['title' => 'Clear your reading list', 'noindex' => true, 'variant' => 'confirm'])->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @param array<string, string> $params
     */
    public function clear(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        if (!$this->validToken($request)) {
            $this->flash->add('error', self::EXPIRED);

            return Response::redirect(PublicUrl::account('continue'), 303);
        }

        $this->progress->deleteForUser($user->id);
        $this->flash->add('success', 'Your reading list is empty.');

        return Response::redirect(PublicUrl::account('continue'), 303);
    }

    /**
     * Stores how far down a chapter the signed-in reader got. Only the reading script calls this, so it answers in
     * plain status codes and a JSON token, never a page. The token works once and the reply carries the next, as the
     * reading-preferences save does.
     *
     * @param array<string, string> $params
     */
    public function savePosition(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return Response::plain('', 401)->withHeader('Cache-Control', 'no-store');
        }

        if (!Csrf::verify(ReadingPositionScript::ACTION, $request->input('csrf_token'), $request->cookies)) {
            return Response::plain('', 400)->withHeader('Cache-Control', 'no-store');
        }

        $story = $this->whole($request->input('story_id'));
        $chapter = $this->whole($request->input('chapter_id'));
        $position = $request->input('position');
        $stored = $story !== null && $chapter !== null && $position !== null && preg_match('/^(0|[1-9][0-9]{0,3})\z/', $position) === 1
            && $this->progress->savePosition($user, $story, $chapter, (int) $position);

        // A refusal still hands back a fresh token, because the one that was sent is used up.
        return Response::plain(json_encode(['token' => Csrf::token(ReadingPositionScript::ACTION)], JSON_THROW_ON_ERROR), $stored ? 200 : 422)
            ->withHeader('Cache-Control', 'no-store');
    }

    private function whole(?string $value): ?int
    {
        return $value !== null && preg_match('/^[1-9][0-9]{0,8}\z/', $value) === 1 ? (int) $value : null;
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

    /**
     * A saved place in thousandths as words, or nothing when the reader has not scrolled into the chapter.
     */
    private function share(int $position): string
    {
        return $position > 0 ? intdiv($position, 10) . '% through' : '';
    }

    /**
     * @param array<string, string> $hidden
     * @return array<string, mixed>
     */
    private function form(string $action, string $submit, array $hidden): array
    {
        return ['action' => $action, 'csrf_token' => Csrf::token(self::ACTION), 'submit' => $submit, 'hidden' => $hidden];
    }

    private function mustSignIn(): Response
    {
        $this->flash->add('info', 'Please sign in to see your reading list.');

        return Response::redirect(PublicUrl::account('sign-in'), 302)->withHeader('Cache-Control', 'private, no-store');
    }

    private function validToken(Request $request): bool
    {
        return Csrf::verify(self::ACTION, $request->input('csrf_token'), $request->cookies);
    }
}
