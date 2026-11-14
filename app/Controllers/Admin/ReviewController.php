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
use LumoraFiction\Core\Admin\BulkResult;
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

        $sort = StoryService::pendingSort($request->queryValue('sort'), $request->queryValue('dir'));
        $total = $this->stories->countPending($user);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) ($request->queryValue('page') ?? 1)));
        $stories = $this->stories->listPending($user, self::PER_PAGE, ($page - 1) * self::PER_PAGE, $sort);

        return $this->view->render('review-queue', [
            'stories' => $stories,
            'sort' => $sort,
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
     * Approves the ticked stories, or sends them back with one shared reason (asked for on a page of its own).
     *
     * @param array<string, string> $params
     */
    public function bulk(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ModerateStories, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $action = $request->input('bulk_action') ?? '';
        $posted = $request->inputList('ids');

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->bulkBack($request, 'error', self::STALE_FORM);
        }

        if ($action !== 'approve' && $action !== 'reject') {
            return $this->bulkBack($request, 'error', 'Choose what to do with the selected stories.');
        }

        if (BulkResult::tooMany($posted)) {
            return $this->bulkBack($request, 'error', 'Too many stories were selected at once.');
        }

        $ids = BulkResult::ids($posted);

        if ($ids === []) {
            return $this->bulkBack($request, 'error', 'Tick at least one story first.');
        }

        $reason = $request->input('reason');

        if ($action === 'reject' && $reason === null) {
            return $this->bulkRejectPage($request, $user, $ids, '', [], 200);
        }

        try {
            $result = $this->stories->bulkReview($user, $action, $ids, $reason ?? '', $request->ip());
        } catch (StoryValidationException $exception) {
            return $this->bulkRejectPage($request, $user, $ids, $reason ?? '', $exception->errors, 422);
        } catch (StoryPermissionException $exception) {
            return $this->bulkBack($request, 'error', $exception->getMessage());
        }

        return $this->bulkBack($request, $result->flashType(), $result->summary($action === 'approve' ? 'approved and published' : 'sent back to their authors as drafts', 'story', 'stories'));
    }

    /**
     * The sort and page the person was on, taken from the posted form and checked again, so a bulk action returns to the same view.
     *
     * @return array<string, string>
     */
    private function kept(Request $request): array
    {
        $page = preg_match('/^[1-9][0-9]{0,5}\z/', $request->input('page') ?? '') === 1 ? (int) $request->input('page') : 1;

        return StoryService::pendingSort($request->input('sort'), $request->input('dir'))->query() + ($page > 1 ? ['page' => (string) $page] : []);
    }

    private function bulkBack(Request $request, string $kind, string $message): Response
    {
        $this->flash->add($kind, $message);
        $kept = $this->kept($request);

        return Response::redirect(AdminUrl::to(self::PATH) . ($kept === [] ? '' : '?' . http_build_query($kept)), 303);
    }

    /**
     * @param list<int> $ids
     * @param array<string, string> $errors
     */
    private function bulkRejectPage(Request $request, User $user, array $ids, string $reason, array $errors, int $status): Response
    {
        // Only stories that are waiting are listed, so the page confirms nothing about any other story.
        $stories = [];

        foreach ($ids as $id) {
            $story = $this->stories->find($id);

            if ($story !== null && $story->state === PublicationState::Pending) {
                $stories[] = $story;
            }
        }

        return $this->view->render('review-reject-bulk', [
            'stories' => $stories,
            'reason' => $reason,
            'limit' => StoryService::REASON_MAX,
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
            'action' => AdminUrl::to(self::PATH . '/bulk'),
            'kept' => $this->kept($request),
            'backUrl' => AdminUrl::to(self::PATH),
        ], $user, 'Send stories back', self::PATH, $this->flash->pull($request->cookies), $status);
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
