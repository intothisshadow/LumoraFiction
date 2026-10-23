<?php

/**
 * The Trash screen: stories and chapters that were moved to the trash, to restore or delete for good.
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
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\Chapter;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ChapterService;
use LumoraFiction\Services\RetentionSettings;
use LumoraFiction\Services\StoryPermissionException;
use LumoraFiction\Services\StoryService;
use LumoraFiction\Services\StoryValidationException;
use LumoraFiction\Services\UserService;

/**
 * Whose items appear is decided by the services in the query, not by this screen: a writer lists and restores only their
 * own, and only a person who may edit every story can ask to see everyone's. Deleting for good asks for confirmation on
 * a page of its own, listing only what the person may delete, so it never confirms that anyone else's items exist.
 */
final class TrashController extends WriterController
{
    private const PATH = 'trash';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly StoryService $stories,
        private readonly ChapterService $chapters,
        private readonly UserService $users,
        private readonly Configuration $config,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function index(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        // Expired items go whenever the trash is opened, since no scheduled job exists to do it.
        $this->stories->purgeExpiredTrash();
        $this->chapters->purgeExpiredTrash();

        $all = $request->queryValue('scope') === 'all' && $user->can(Capability::EditAnyStory);
        $stories = $this->stories->listTrashed($user, $all);
        $chapters = $this->chapters->listTrashed($user, $all);

        return $this->view->render('trash', [
            'stories' => $stories,
            'chapters' => $chapters,
            'owners' => $all ? $this->users->displayNames(array_map(static fn (Story $story): int => $story->ownerId, $stories)) : [],
            'all' => $all,
            'canSeeAll' => $user->can(Capability::EditAnyStory),
            'days' => RetentionSettings::trashDays($this->config),
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
            'action' => AdminUrl::to(self::PATH . '/act'),
            'listUrl' => AdminUrl::to(self::PATH),
        ], $user, 'Trash', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * Restores or deletes what was ticked, or everything shown for "empty".
     *
     * @param array<string, string> $params
     */
    public function act(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $back = Response::redirect(AdminUrl::to(self::PATH), 303);
        $action = $request->input('trash_action') ?? '';

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            $this->flash->add('error', self::STALE_FORM);

            return $back;
        }

        if (!in_array($action, ['restore', 'delete', 'empty'], true)) {
            $this->flash->add('error', 'Choose what to do with the ticked items.');

            return $back;
        }

        if ($action === 'empty') {
            $storyIds = array_map(static fn (Story $story): int => $story->id, $this->stories->listTrashed($user));
            $chapterIds = array_map(static fn (array $row): int => $row['chapter']->id, $this->chapters->listTrashed($user));
            $action = 'delete';
        } else {
            $storyIds = $this->postedIds($request, 'stories');
            $chapterIds = $this->postedIds($request, 'chapters');
        }

        if ($storyIds === [] && $chapterIds === []) {
            $this->flash->add('error', $request->input('trash_action') === 'empty' ? 'The trash is already empty.' : 'Tick at least one item first.');

            return $back;
        }

        if ($action === 'delete' && !$request->checked('confirmed')) {
            return $this->confirmPage($request, $user, $storyIds, $chapterIds);
        }

        $done = 0;
        $skipped = 0;

        foreach ($storyIds as $id) {
            try {
                $action === 'restore' ? $this->stories->restore($user, $id, $request->ip()) : $this->stories->purge($user, $id, $request->ip());
                $done++;
            } catch (StoryPermissionException) {
                $skipped++;
            }
        }

        foreach ($chapterIds as $id) {
            try {
                $action === 'restore' ? $this->chapters->restore($user, $id, $request->ip()) : $this->chapters->purge($user, $id, $request->ip());
                $done++;
            } catch (StoryPermissionException | StoryValidationException) {
                $skipped++;
            }
        }

        $this->flash->add(
            $done > 0 ? 'success' : 'warning',
            $done . ($done === 1 ? ' item was ' : ' items were ') . ($action === 'restore' ? 'restored as drafts or unpublished. Publish them again when you are ready' : 'deleted for good') . '.'
            . ($skipped > 0 ? ' ' . $skipped . ' could not be changed (a chapter whose story is still in the trash needs the story restored first).' : ''),
        );

        return $back;
    }

    /**
     * @param list<int> $storyIds
     * @param list<int> $chapterIds
     */
    private function confirmPage(Request $request, User $user, array $storyIds, array $chapterIds): Response
    {
        $stories = [];
        $chapters = [];

        foreach ($storyIds as $id) {
            try {
                $story = $this->stories->manageableIncludingTrashed($user, $id);

                if ($story->isTrashed()) {
                    $stories[] = $story;
                }
            } catch (StoryPermissionException) {
                continue;
            }
        }

        foreach ($chapterIds as $id) {
            try {
                $chapter = $this->chapters->manageableIncludingTrashed($user, $id);

                if ($chapter->isTrashed()) {
                    $chapters[] = ['chapter' => $chapter, 'story_title' => $this->stories->find($chapter->storyId)?->title ?? ''];
                }
            } catch (StoryPermissionException) {
                continue;
            }
        }

        return $this->view->render('trash-confirm', [
            'stories' => $stories,
            'chapters' => $chapters,
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
            'action' => AdminUrl::to(self::PATH . '/act'),
            'backUrl' => AdminUrl::to(self::PATH),
        ], $user, 'Delete for good', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @return list<int>
     */
    private function postedIds(Request $request, string $name): array
    {
        $ids = [];

        foreach ($request->inputList($name) as $value) {
            if (preg_match('/^[1-9][0-9]{0,9}\z/', $value) === 1) {
                $ids[] = (int) $value;
            }
        }

        return array_slice(array_values(array_unique($ids)), 0, 200);
    }
}
