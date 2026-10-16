<?php

/**
 * The approval queue: stories waiting for an editor or administrator, with approve and reject.
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

namespace LumoraFiction\Controllers\Admin;

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Admin\AdminView;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ChapterService;
use LumoraFiction\Services\StoryPermissionException;
use LumoraFiction\Services\StoryService;
use LumoraFiction\Services\StoryValidationException;
use LumoraFiction\Services\UserService;

/**
 * Every action is decided by the story service, which refuses anyone without the review capability. A story
 * that another reviewer has already handled is reported as such rather than changed twice.
 */
final class ReviewController extends WriterController
{
    private const PATH = 'review';

    private const PER_PAGE = 25;

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly StoryService $stories,
        private readonly ChapterService $chapters,
        private readonly UserService $users,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function index(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ModerateStories, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $total = $this->stories->countPending($user);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) ($request->queryValue('page') ?? 1)));
        $stories = $this->stories->listPending($user, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return $this->view->render('review-queue', [
            'stories' => $stories,
            'owners' => $this->users->displayNames(array_map(static fn (Story $story): int => $story->ownerId, $stories)),
            'chapterCounts' => $this->chapters->countsForStories(array_map(static fn (Story $story): int => $story->id, $stories)),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'listUrl' => AdminUrl::to(self::PATH),
            'storyUrl' => AdminUrl::to('stories'),
            'reviewUrl' => AdminUrl::to(self::PATH),
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
        ], $user, 'Awaiting approval', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $params
     */
    public function approve(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ModerateStories, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $id = $this->idFrom($params);

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->back('error', self::STALE_FORM);
        }

        try {
            $story = $this->stories->approve($user, $id, $request->ip());
        } catch (StoryValidationException $exception) {
            return $this->back('error', implode(' ', $exception->errors));
        } catch (StoryPermissionException $exception) {
            return $this->back('error', $exception->getMessage());
        }

        return $this->back('success', '"' . $story->title . '" was approved and is published.');
    }

    /**
     * @param array<string, string> $params
     */
    public function showReject(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ModerateStories, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $story = $this->pending($params);

        return $story === null ? $this->back('error', 'That story is not waiting for approval.') : $this->rejectPage($request, $user, $story, '', [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function reject(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ModerateStories, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $story = $this->pending($params);

        if ($story === null) {
            return $this->back('error', 'That story is not waiting for approval.');
        }

        $reason = $request->input('reason') ?? '';

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->rejectPage($request, $user, $story, $reason, ['form' => self::STALE_FORM], 400);
        }

        try {
            $this->stories->reject($user, $story->id, $reason, $request->ip());
        } catch (StoryValidationException $exception) {
            if (isset($exception->errors['state'])) {
                return $this->back('error', $exception->errors['state']);
            }

            return $this->rejectPage($request, $user, $story, $reason, $exception->errors, 422);
        } catch (StoryPermissionException $exception) {
            return $this->back('error', $exception->getMessage());
        }

        return $this->back('success', '"' . $story->title . '" was sent back to its author as a draft, with your reason.');
    }

    /**
     * @param array<string, string> $params
     */
    private function pending(array $params): ?Story
    {
        $id = $this->idFrom($params);
        $story = $id === 0 ? null : $this->stories->find($id);

        return $story !== null && $story->state === PublicationState::Pending ? $story : null;
    }

    /**
     * @param array<string, string> $errors
     */
    private function rejectPage(Request $request, User $user, Story $story, string $reason, array $errors, int $status): Response
    {
        return $this->view->render('review-reject', [
            'story' => $story,
            'owner' => $this->users->displayNames([$story->ownerId])[$story->ownerId] ?? '',
            'reason' => $reason,
            'limit' => StoryService::REASON_MAX,
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
            'action' => AdminUrl::to(self::PATH . '/' . $story->id . '/reject'),
            'backUrl' => AdminUrl::to(self::PATH),
            'previewUrl' => AdminUrl::to('stories/' . $story->id . '/preview'),
        ], $user, 'Send back ' . $story->title, self::PATH, $this->flash->pull($request->cookies), $status);
    }

    private function back(string $kind, string $message): Response
    {
        $this->flash->add($kind, $message);

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }
}
