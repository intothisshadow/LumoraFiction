<?php

/**
 * The public side of comments: posting under a chapter, editing your own, deleting, and reporting.
 *
 * @package LumoraFiction
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers;

use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Models\CommentStatus;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ChapterService;
use LumoraFiction\Services\CommentException;
use LumoraFiction\Services\CommentGuard;
use LumoraFiction\Services\CommentPresenter;
use LumoraFiction\Services\CommentService;
use LumoraFiction\Services\StoryService;

/**
 * Every action needs a signed-in person, a valid form token and a chapter that is open to readers; the checks that decide
 * what a person may do live in CommentService, so a hand-made request meets the same rules as the form. Pages that show a
 * form are private to one reader: never cached and never indexed. Where a request returns to is rebuilt from the database,
 * never taken from the request.
 */
final class CommentController
{
    private const EXPIRED = 'Your session expired. Please try again.';

    public function __construct(
        private readonly ThemeRenderer $themes,
        private readonly AuthService $auth,
        private readonly CommentService $comments,
        private readonly CommentPresenter $presenter,
        private readonly CommentGuard $guard,
        private readonly StoryService $stories,
        private readonly ChapterService $chapters,
        private readonly FlashMessages $flash,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function post(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        $story = preg_match('/^[1-9][0-9]{0,8}\z/', $params['number'] ?? '') === 1 ? $this->stories->findPublicBySlug($params['slug'] ?? '') : null;
        $chapter = $story === null ? null : $this->chapters->findPublic($story->id, (int) $params['number']);

        if ($story === null || $chapter === null) {
            return $this->themes->notFound()->withHeader('Cache-Control', 'private, no-store');
        }

        $target = $this->comments->target($chapter->id);
        $body = $request->input('body') ?? '';
        $back = PublicUrl::chapter($story->slug, $chapter->number);

        if ($target === null) {
            return $this->themes->notFound()->withHeader('Cache-Control', 'private, no-store');
        }

        if (!Csrf::verify(CommentPresenter::POST_ACTION, $request->input('csrf_token'), $request->cookies)) {
            return $this->formPage($user, $target, $body, self::EXPIRED, 400, $back);
        }

        try {
            // Everything that can be refused is checked before the spam guard runs, so Akismet is never asked about a comment that cannot be posted anyway.
            $clean = $this->comments->cleanBody($body);
            $verdict = $this->guard->inspect($request, $user, $clean, CommentPresenter::POST_ACTION);

            if ($verdict['outcome'] === CommentGuard::RETRY) {
                return $this->formPage($user, $target, $body, $verdict['error'], 422, $back);
            }

            $result = $this->comments->post($user, $chapter->id, $clean, $request->ip(), $verdict['outcome'] === CommentGuard::SPAM);
        } catch (CommentException $exception) {
            return $this->formPage($user, $target, $body, $exception->getMessage(), 422, $back);
        }

        // A comment kept as spam is answered like one that is waiting, so a bot learns nothing from the reply.
        if ($result['held'] || $result['spam']) {
            $this->flash->add('info', 'Thank you. Your comment is waiting for approval and will appear once a moderator has seen it.');
            $anchor = '#comments';
        } else {
            $this->flash->add('success', 'Your comment was posted.');
            $anchor = '#comment-' . $result['comment']->id;
        }

        return Response::redirect($back . $anchor, 303)->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @param array<string, string> $params
     */
    public function showEdit(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        $comment = $this->comments->find($this->id($params));

        if ($comment === null || !$this->comments->mayEdit($user, $comment)) {
            return $this->gone($request, 'That comment can no longer be edited.');
        }

        return $this->editPage($user, $comment->id, $comment->body, '', 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        $id = $this->id($params);
        $body = $request->input('body') ?? '';

        if (!Csrf::verify(CommentPresenter::EDIT_ACTION, $request->input('csrf_token'), $request->cookies)) {
            return $this->editPage($user, $id, $body, self::EXPIRED, 400);
        }

        try {
            $comment = $this->comments->edit($user, $id, $body);
        } catch (CommentException $exception) {
            return $this->editPage($user, $id, $body, $exception->getMessage(), 422);
        }

        $this->flash->add('success', 'Your comment was changed.');

        return $this->returnTo($comment->chapterId, $comment->id);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        $comment = $this->comments->find($this->id($params));

        if (!Csrf::verify(CommentPresenter::DELETE_ACTION, $request->input('csrf_token'), $request->cookies)) {
            $this->flash->add('error', self::EXPIRED);

            return $comment === null ? Response::redirect(PublicUrl::account('sign-in'), 303) : $this->returnTo($comment->chapterId, $comment->id);
        }

        if ($comment === null) {
            return $this->gone($request, 'That comment is not available.');
        }

        try {
            $this->comments->deleteOne($user, $comment->id, $request->ip());
        } catch (CommentException $exception) {
            return $this->gone($request, $exception->getMessage());
        }

        $this->flash->add('success', 'The comment was deleted.');

        return $this->returnTo($comment->chapterId, 0);
    }

    /**
     * @param array<string, string> $params
     */
    public function showReport(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        $comment = $this->comments->find($this->id($params));

        if ($comment === null || $comment->status !== CommentStatus::Published || $comment->userId === $user->id) {
            return $this->gone($request, 'That comment is not available.');
        }

        return $this->reportPage($user, $comment->id, '', '', 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function report(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->mustSignIn();
        }

        $id = $this->id($params);
        $reason = $request->input('reason') ?? '';

        if (!Csrf::verify(CommentPresenter::REPORT_ACTION, $request->input('csrf_token'), $request->cookies)) {
            return $this->reportPage($user, $id, $reason, self::EXPIRED, 400);
        }

        $comment = $this->comments->find($id);

        try {
            $added = $this->comments->report($user, $id, $reason);
        } catch (CommentException $exception) {
            return $this->reportPage($user, $id, $reason, $exception->getMessage(), 422);
        }

        // The same words whether this was the first report or a repeat, and never anything about who the commenter is.
        $this->flash->add('success', $added ? 'Thank you. A moderator will look at that comment.' : 'You have already reported that comment. A moderator will look at it.');

        return $comment === null ? Response::redirect(PublicUrl::account('sign-in'), 303) : $this->returnTo($comment->chapterId, $comment->id);
    }

    // ---- pages --------------------------------------------------------------------------------------------------------

    /**
     * @param array{story_id: int, chapter_id: int, owner_id: int, number: int, title: string, story_title: string, story_slug: string} $target
     */
    private function formPage(User $user, array $target, string $body, string $error, int $status, string $back): Response
    {
        $form = $this->presenter->postForm($user, $target, $body, $error);

        if ($form === null) {
            // Nothing to show a form for: the reason, and the way back.
            return $this->account('Comment', [], $status, $error !== '' ? $error : 'Comments are not available to you here.', $back, 'Back to the chapter');
        }

        return $this->account('Comment', [$form], $status, '', $back, 'Back to the chapter');
    }

    private function editPage(User $user, int $id, string $body, string $error, int $status): Response
    {
        $form = $this->presenter->form(
            CommentPresenter::EDIT_ACTION,
            PublicUrl::commentAction($id, 'edit'),
            'Save comment',
            [$this->presenter->bodyField($body)],
            'Edit your comment',
            $error,
        );

        return $this->account('Edit comment', [$form], $status, 'You can change your own words for a short time after posting. Nobody else can edit them.', $this->back($id), 'Cancel');
    }

    private function reportPage(User $user, int $id, string $reason, string $error, int $status): Response
    {
        $form = $this->presenter->form(
            CommentPresenter::REPORT_ACTION,
            PublicUrl::commentAction($id, 'report'),
            'Send report',
            [[
                'name' => 'reason',
                'id' => 'lf-field-report-reason',
                'label' => 'Why are you reporting it? (optional)',
                'type' => 'textarea',
                'value' => $reason,
                'autocomplete' => 'off',
                'hint' => 'A moderator will read this. The person who wrote the comment is never told who reported it.',
                'error' => '',
                'options' => [],
                'rows' => 3,
                'maxlength' => CommentService::REASON_MAX,
                'required' => false,
            ]],
            'Report a comment',
            $error,
        );

        return $this->account('Report a comment', [$form], $status, '', $this->back($id), 'Cancel');
    }

    /**
     * @param list<array<string, mixed>> $forms
     */
    private function account(string $title, array $forms, int $status, string $intro, string $backUrl, string $backLabel): Response
    {
        return $this->themes->render('account', [
            'intro' => $intro,
            'notices' => [],
            'forms' => $forms,
            'links' => [['label' => $backLabel, 'url' => $backUrl]],
            'shortcuts' => [],
        ], $status, ['title' => $title, 'noindex' => true, 'variant' => 'comment'])->withHeader('Cache-Control', 'private, no-store');
    }

    private function gone(Request $request, string $message): Response
    {
        $this->flash->add('error', $message);

        return Response::redirect(PublicUrl::account('profile'), 303)->withHeader('Cache-Control', 'private, no-store');
    }

    /** The chapter a comment sits under, rebuilt from the database; the home page when it is no longer open. */
    private function returnTo(int $chapterId, int $commentId): Response
    {
        $target = $this->comments->target($chapterId);
        $url = $target === null ? PublicUrl::account('profile') : PublicUrl::chapter($target['story_slug'], $target['number']) . ($commentId > 0 ? '#comment-' . $commentId : '#comments');

        return Response::redirect($url, 303)->withHeader('Cache-Control', 'private, no-store');
    }

    private function back(int $commentId): string
    {
        $comment = $this->comments->find($commentId);
        $target = $comment === null ? null : $this->comments->target($comment->chapterId);

        return $target === null ? PublicUrl::account('profile') : PublicUrl::chapter($target['story_slug'], $target['number']) . '#comment-' . $commentId;
    }

    /**
     * @param array<string, string> $params
     */
    private function id(array $params): int
    {
        $id = $params['id'] ?? '';

        return preg_match('/^[1-9][0-9]{0,15}\z/', $id) === 1 ? (int) $id : 0;
    }

    private function mustSignIn(): Response
    {
        $this->flash->add('info', 'Please sign in to comment.');

        return Response::redirect(PublicUrl::account('sign-in'), 302)->withHeader('Cache-Control', 'private, no-store');
    }
}
