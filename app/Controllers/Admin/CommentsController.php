<?php

/**
 * The Comments screen: every comment a person may moderate, with tabs, filters and single or bulk actions.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers\Admin;

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Admin\AdminView;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\CommentStatus;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\CommentException;
use LumoraFiction\Services\CommentService;

/**
 * An Administrator or Editor sees every comment; a writer sees only the comments under their own stories. The limit is in
 * the query itself, so a hand-made request for another writer's comment finds nothing. Every change is POST-only, needs a
 * valid form token, and is written to the audit log.
 */
final class CommentsController extends AdminController
{
    private const PATH = 'comments';

    private const TOKEN = 'admin_comments';

    private const PER_PAGE = 30;

    private const STALE_FORM = 'Your session expired, so nothing was changed. Please check the list and try again.';

    private const TABS = ['' => 'All', 'published' => 'Published', 'pending' => 'Pending', 'reported' => 'Reported', 'hidden' => 'Hidden', 'spam' => 'Spam'];

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly CommentService $comments,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function index(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::WriteStories, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $filters = $this->filters($request->query);
        $total = $this->comments->countMatching($user, $filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) ($request->queryValue('page') ?? 1)));
        $rows = $this->comments->search($user, $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $reports = $this->comments->reportsFor(array_map(static fn (array $row): int => $row['comment']->id, array_filter($rows, static fn (array $row): bool => $row['comment']->reportCount > 0)));
        $isModerator = $this->comments->isModerator($user);

        return $this->view->render('comments-list', [
            'rows' => $rows,
            'reports' => $reports,
            'filters' => $filters,
            'tabs' => self::TABS,
            'counts' => $this->comments->tabCounts($user, array_diff_key($filters, ['status' => true])),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
            'bulkLimit' => CommentService::BULK_LIMIT,
            'isModerator' => $isModerator,
            'commenter' => $isModerator && $filters['user'] > 0 ? ['id' => $filters['user']] + $this->comments->accountSummary($filters['user']) : null,
            'listUrl' => AdminUrl::to(self::PATH),
            'bulkUrl' => AdminUrl::to(self::PATH . '/bulk'),
            'accountUrl' => AdminUrl::to(self::PATH . '/account'),
            'chapterUrl' => static fn (array $row): string => PublicUrl::chapter($row['story_slug'], $row['chapter_number']) . (in_array($row['comment']->status, [CommentStatus::Published, CommentStatus::Pending], true) ? '#comment-' . $row['comment']->id : ''),
            'csrfToken' => Csrf::token(self::TOKEN),
        ], $user, 'Comments', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $params
     */
    public function bulk(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::WriteStories, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $filters = $this->filters($request->post);
        $page = max(1, (int) ($request->input('page') ?? 1));
        $back = Response::redirect(AdminUrl::to(self::PATH) . $this->query($filters, $page), 303);

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);

            return $back;
        }

        $action = $request->input('bulk_action') ?? '';
        $everything = $request->checked('all_matching');
        $confirmed = $request->checked('confirmed');

        if (!in_array($action, CommentService::ACTIONS, true)) {
            $this->flash->add('error', 'Choose what to do with the selected comments.');

            return $back;
        }

        if ($everything) {
            // Read now, from the filter as it stands, one more than the cap so "too many" can be told from "exactly the cap".
            $ids = $this->comments->idsMatching($user, $filters, CommentService::BULK_LIMIT + 1);

            if (count($ids) > CommentService::BULK_LIMIT) {
                $this->flash->add('error', 'More than ' . CommentService::BULK_LIMIT . ' comments match. Narrow the list with the tabs and filters, then try again.');

                return $back;
            }

            if ($confirmed && count($ids) !== (int) ($request->input('expected') ?? -1)) {
                $this->flash->add('error', 'The list changed since you asked, so nothing was changed. Please check it and try again.');

                return $back;
            }
        } else {
            $ids = [];

            foreach ($request->inputList('ids') as $value) {
                if (preg_match('/^[1-9][0-9]{0,15}\z/', $value) === 1) {
                    $ids[] = (int) $value;
                }
            }

            $ids = array_values(array_unique($ids));
        }

        if ($ids === []) {
            $this->flash->add('error', $everything ? 'Nothing matches the current list.' : 'Tick at least one comment first.');

            return $back;
        }

        // Deleting is for good, and an action on every match changes more than the person can see, so both ask first.
        if (($action === 'delete' || $everything) && !$confirmed) {
            return $this->view->render('comments-confirm', [
                'action' => $action,
                'count' => count($ids),
                'ids' => $everything ? [] : $ids,
                'everything' => $everything,
                // Deleting a comment takes its replies with it, so the question says how many.
                'replies' => $action === 'delete' ? $this->comments->repliesGoingWith($ids) : 0,
                'filters' => $filters,
                'page' => $page,
                'csrfToken' => Csrf::token(self::TOKEN),
                'formAction' => AdminUrl::to(self::PATH . '/bulk'),
                'backUrl' => AdminUrl::to(self::PATH) . $this->query($filters, $page),
            ], $user, 'Confirm', self::PATH, $this->flash->pull($request->cookies));
        }

        $goingWith = $action === 'delete' ? $this->comments->repliesGoingWith($ids) : 0;

        try {
            $result = $this->comments->moderate($user, $action, $ids, $request->ip());
        } catch (CommentException $exception) {
            $this->flash->add('error', $exception->getMessage());

            return $back;
        }

        $verb = ['approve' => 'approved', 'hide' => 'hidden', 'spam' => 'marked as spam', 'restore' => 'restored', 'delete' => 'deleted'][$action];
        $noun = static fn (int $count): string => $count . ($count === 1 ? ' comment' : ' comments');
        $message = $result['changed'] > 0 ? $noun($result['changed']) . ($result['changed'] === 1 ? ' was ' : ' were ') . $verb . '.' : 'No comment was ' . $verb . '.';

        if ($action === 'delete' && $result['changed'] > 0 && $goingWith > 0) {
            $message .= ' ' . ($goingWith === 1 ? '1 reply' : $goingWith . ' replies') . ' went with ' . ($result['changed'] === 1 ? 'it' : 'them') . '.';
        }

        if ($result['skipped'] > 0) {
            $message .= ' ' . $noun($result['skipped']) . ' left alone (already in that state, or not yours to change).';
        }

        $this->flash->add($result['changed'] > 0 ? 'success' : 'warning', $message);

        return $back;
    }

    /**
     * Turns comments off or on for a story or one chapter. The writer of the story may, while the site lets writers, and staff always.
     *
     * @param array<string, string> $params
     */
    public function closure(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::WriteStories, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $storyId = preg_match('/^[1-9][0-9]{0,9}\z/', $request->input('story') ?? '') === 1 ? (int) $request->input('story') : 0;
        $chapterId = preg_match('/^[1-9][0-9]{0,9}\z/', $request->input('chapter') ?? '') === 1 ? (int) $request->input('chapter') : 0;
        $back = Response::redirect(AdminUrl::to($chapterId > 0 && $request->input('return') === 'chapter' ? 'chapters/' . $chapterId : 'stories/' . $storyId), 303);

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);

            return $back;
        }

        try {
            $this->comments->setClosed($user, $storyId, $chapterId, $request->input('closed') === '1', $request->ip());
        } catch (CommentException $exception) {
            $this->flash->add('error', $exception->getMessage());

            return $back;
        }

        $this->flash->add('success', $request->input('closed') === '1' ? 'Comments are turned off.' : 'Comments are turned on.');

        return $back;
    }

    /**
     * Holds or blocks one account's future comments, or clears either. Staff only.
     *
     * @param array<string, string> $params
     */
    public function account(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ModerateComments, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $userId = preg_match('/^[1-9][0-9]{0,9}\z/', $request->input('user_id') ?? '') === 1 ? (int) $request->input('user_id') : 0;
        $back = Response::redirect(AdminUrl::to(self::PATH) . ($userId > 0 ? '?user=' . $userId : ''), 303);

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);

            return $back;
        }

        $mode = $request->input('mode') ?? '';

        try {
            $this->comments->setModeration($user, $userId, $mode === 'clear' ? '' : $mode, $request->ip());
        } catch (CommentException $exception) {
            $this->flash->add('error', $exception->getMessage());

            return $back;
        }

        $this->flash->add('success', match ($mode) {
            'hold' => 'This account\'s future comments will wait for approval.',
            'block' => 'This account can no longer post comments.',
            default => 'This account\'s comments are no longer held or blocked.',
        });

        return $back;
    }

    /**
     * @param array<string, mixed> $source the query string or the posted form
     * @return array{status: string, story: int, chapter: int, user: int, from: string, to: string, q: string, kind: string}
     */
    private function filters(array $source): array
    {
        $text = static fn (string $key): string => is_string($source[$key] ?? null) ? trim($source[$key]) : '';
        $whole = static fn (string $key): int => preg_match('/^[1-9][0-9]{0,9}\z/', is_string($source[$key] ?? null) ? $source[$key] : '') === 1 ? (int) $source[$key] : 0;
        $status = $text('status');

        return [
            'status' => in_array($status, ['published', 'pending', 'hidden', 'spam', 'reported'], true) ? $status : '',
            'story' => $whole('story'),
            'chapter' => $whole('chapter'),
            'user' => $whole('user'),
            'from' => preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $text('from')) === 1 ? $text('from') : '',
            'to' => preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $text('to')) === 1 ? $text('to') : '',
            'q' => mb_substr($text('q'), 0, 100),
            'kind' => $text('kind') === 'replies' ? 'replies' : '',
        ];
    }

    /**
     * @param array{status: string, story: int, chapter: int, user: int, from: string, to: string, q: string, kind: string} $filters
     */
    private function query(array $filters, int $page): string
    {
        $parts = array_filter($filters + ['page' => $page > 1 ? $page : ''], static fn (mixed $value): bool => $value !== '' && $value !== 0);

        return $parts === [] ? '' : '?' . http_build_query($parts);
    }
}
