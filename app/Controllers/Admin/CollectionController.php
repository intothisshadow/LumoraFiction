<?php

/**
 * The admin screens for collections: list, add, edit, the approval queue, offering a story, delete.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers\Admin;

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Admin\AdminView;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\Collection;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\CollectionService;
use LumoraFiction\Services\GroupingException;
use LumoraFiction\Services\GroupingSettings;
use LumoraFiction\Services\StoryService;

/**
 * Permission is the right to write stories, checked here on every route; the service checks again who curates the
 * collection and whose story it is. A collection that does not exist and one the person may not touch are answered the same
 * way. While collections are switched off every screen is refused, and nothing stored is lost.
 */
final class CollectionController extends WriterController
{
    private const PATH = 'collections';

    private const TOKEN = 'admin_collections';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly CollectionService $collections,
        private readonly StoryService $stories,
        private readonly Configuration $config,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function index(array $params, Request $request): Response
    {
        $user = $this->open($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $all = $request->queryValue('scope') === 'all' && $user->can(Capability::EditAnyStory);
        $mine = $this->stories->listForOwner($user->id);

        return $this->view->render('collections-list', [
            'rows' => $this->collections->listFor($user, $all),
            'all' => $all,
            'mayListAll' => $user->can(Capability::EditAnyStory),
            'mayCreate' => $this->collections->mayCreate($user),
            'open' => $this->collections->open($user),
            'submissions' => $this->collections->submissionsBy($user),
            'myStories' => $mine,
            'csrfToken' => Csrf::token(self::TOKEN),
            'newUrl' => AdminUrl::to(self::PATH . '/new'),
            'baseUrl' => AdminUrl::to(self::PATH),
        ], $user, 'Collections', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $params
     */
    public function showCreate(array $params, Request $request): Response
    {
        $user = $this->open($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->collections->mayCreate($user)) {
            return $this->missing('You may not start a collection on this site.', self::PATH);
        }

        return $this->formPage($request, $user, null, ['title' => '', 'description' => '', 'visibility' => 'public', 'accepts_submissions' => false, 'requires_approval' => true], [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(array $params, Request $request): Response
    {
        $user = $this->open($request, self::PATH, self::PATH . '/new', ['title', 'description']);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->collections->mayCreate($user)) {
            return $this->missing('You may not start a collection on this site.', self::PATH);
        }

        $values = $this->posted($request);

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->formPage($request, $user, null, $values, ['form' => self::STALE_FORM], 400);
        }

        try {
            $collection = $this->collections->create($user, $values, $request->ip());
        } catch (GroupingException $exception) {
            return $this->formPage($request, $user, null, $values, $exception->errors !== [] ? $exception->errors : ['form' => $exception->getMessage()], 422);
        }

        $this->flash->add('success', 'Collection added.');

        return Response::redirect(AdminUrl::to(self::PATH . '/' . $collection->id), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function showEdit(array $params, Request $request): Response
    {
        $user = $this->open($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $collection = $this->curated($user, $params);

        if ($collection === null) {
            return $this->missing('That collection is not available.', self::PATH);
        }

        return $this->formPage($request, $user, $collection, [
            'title' => $collection->title,
            'description' => $collection->description,
            'visibility' => $collection->isPublic ? 'public' : 'private',
            'accepts_submissions' => $collection->acceptsSubmissions,
            'requires_approval' => $collection->requiresApproval,
        ], [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(array $params, Request $request): Response
    {
        $user = $this->open($request, self::PATH, self::PATH . '/' . $this->idFrom($params), ['title', 'description']);

        if ($user instanceof Response) {
            return $user;
        }

        $collection = $this->curated($user, $params);

        if ($collection === null) {
            return $this->missing('That collection is not available.', self::PATH);
        }

        $values = $this->posted($request);

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->formPage($request, $user, $collection, $values, ['form' => self::STALE_FORM], 400);
        }

        try {
            $this->collections->update($user, $collection->id, $values, $request->ip());
        } catch (GroupingException $exception) {
            return $this->formPage($request, $user, $collection, $values, $exception->errors !== [] ? $exception->errors : ['form' => $exception->getMessage()], 422);
        }

        $this->flash->add('success', 'Collection saved.');

        return Response::redirect(AdminUrl::to(self::PATH . '/' . $collection->id), 303);
    }

    /**
     * Offers one of the person's own stories to a collection: their own, or an open one run by someone else.
     *
     * @param array<string, string> $params
     */
    public function submit(array $params, Request $request): Response
    {
        return $this->act($params, $request, false, function (User $user, Collection $collection) use ($request): string {
            $state = $this->collections->submit($user, $collection->id, (int) $request->input('story_id'), (string) $request->input('note'), $request->ip());

            return $state === 'approved' ? 'Story added to the collection.' : 'Story offered. It will show once the curator approves it.';
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function withdraw(array $params, Request $request): Response
    {
        return $this->act($params, $request, false, function (User $user, Collection $collection) use ($request): string {
            $this->collections->withdraw($user, $collection->id, (int) $request->input('story_id'), $request->ip());

            return 'Story taken out of the collection.';
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function approve(array $params, Request $request): Response
    {
        return $this->act($params, $request, true, function (User $user, Collection $collection) use ($request): string {
            $this->collections->approve($user, $collection->id, (int) $request->input('story_id'), $request->ip());

            return 'Story approved.';
        });
    }

    /**
     * Approves, turns down or removes the ticked stories of a collection the person runs. The stories themselves are
     * untouched, and their authors can offer them again, so nothing is asked first.
     *
     * @param array<string, string> $params
     */
    public function bulk(array $params, Request $request): Response
    {
        $user = $this->open($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $collection = $this->collections->find($this->idFrom($params));

        if ($collection === null || !$this->collections->canManage($user, $collection)) {
            return $this->missing('That collection is not available.', self::PATH);
        }

        $back = AdminUrl::to(self::PATH . '/' . $collection->id);
        $input = $this->bulkInput($request, self::TOKEN, CollectionService::BULK_ACTIONS, 'stories', $back);

        if ($input instanceof Response) {
            return $input;
        }

        try {
            $result = $this->collections->bulk($user, $collection->id, $input['action'], $input['ids'], $request->ip());
        } catch (GroupingException $exception) {
            return $this->missing($exception->getMessage(), self::PATH);
        }

        $this->flash->add($result->flashType(), $result->summary(['approve' => 'approved', 'turndown' => 'turned down', 'remove' => 'taken out of the collection'][$input['action']], 'story', 'stories'));

        return Response::redirect($back, 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function remove(array $params, Request $request): Response
    {
        return $this->act($params, $request, true, function (User $user, Collection $collection) use ($request): string {
            $this->collections->remove($user, $collection->id, (int) $request->input('story_id'), $request->ip());

            return 'Story taken out of the collection.';
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function showDelete(array $params, Request $request): Response
    {
        $user = $this->open($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $collection = $this->curated($user, $params);

        return $collection === null ? $this->missing('That collection is not available.', self::PATH) : $this->deletePage($request, $user, $collection, 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(array $params, Request $request): Response
    {
        $user = $this->open($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $collection = $this->curated($user, $params);

        if ($collection === null) {
            return $this->missing('That collection is not available.', self::PATH);
        }

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->deletePage($request, $user, $collection, 400, self::STALE_FORM);
        }

        try {
            $this->collections->delete($user, $collection->id, $request->ip());
        } catch (GroupingException $exception) {
            return $this->missing($exception->getMessage(), self::PATH);
        }

        $this->flash->add('success', 'Collection deleted. The stories in it were not touched.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * The same steps for every button: check the person and the token, run the change, say what happened.
     *
     * @param array<string, string> $params
     * @param bool $curatorOnly whether only the person who runs the collection may press it
     * @param callable(User, Collection): string $change returns the message to show when it worked
     */
    private function act(array $params, Request $request, bool $curatorOnly, callable $change): Response
    {
        $user = $this->open($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $collection = $this->collections->find($this->idFrom($params));

        if ($collection === null || ($curatorOnly && !$this->collections->canManage($user, $collection))) {
            return $this->missing('That collection is not available.', self::PATH);
        }

        $back = Response::redirect(AdminUrl::to(self::PATH . ($this->collections->canManage($user, $collection) ? '/' . $collection->id : '')), 303);

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);

            return $back;
        }

        try {
            $this->flash->add('success', $change($user, $collection));
        } catch (GroupingException $exception) {
            $this->flash->add('error', $exception->getMessage());
        }

        return $back;
    }

    /**
     * @param list<string> $keep
     */
    private function open(Request $request, string $currentPath, string $returnPath = '', array $keep = []): User|Response
    {
        $user = $this->writer($request, $currentPath, $returnPath, $keep);

        if ($user instanceof Response) {
            return $user;
        }

        if (!GroupingSettings::collectionsEnabled($this->config)) {
            return $this->missing('Collections are switched off on this site.', 'stories');
        }

        return $user;
    }

    /**
     * @param array<string, string> $params
     */
    private function curated(User $user, array $params): ?Collection
    {
        $collection = $this->collections->find($this->idFrom($params));

        return $collection !== null && $this->collections->canManage($user, $collection) ? $collection : null;
    }

    /**
     * @return array{title: string, description: string, visibility: string, accepts_submissions: bool, requires_approval: bool}
     */
    private function posted(Request $request): array
    {
        return [
            'title' => (string) $request->input('title'),
            'description' => (string) $request->input('description'),
            'visibility' => (string) $request->input('visibility'),
            'accepts_submissions' => $request->checked('accepts_submissions'),
            'requires_approval' => $request->checked('requires_approval'),
        ];
    }

    /**
     * @param array{title: string, description: string, visibility: string, accepts_submissions: bool, requires_approval: bool} $values
     * @param array<string, string> $errors
     */
    private function formPage(Request $request, User $user, ?Collection $collection, array $values, array $errors, int $status): Response
    {
        $members = $collection === null ? [] : $this->collections->members($collection->id);
        $taken = [];

        foreach ($members as $member) {
            $taken[$member['story']->id] = true;
        }

        $available = array_values(array_filter(
            $collection === null ? [] : $this->stories->listForOwner($user->id),
            static fn ($story): bool => !isset($taken[$story->id]),
        ));

        return $this->view->render('collection-form', [
            'collection' => $collection,
            'values' => $values,
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'pending' => array_values(array_filter($members, static fn (array $member): bool => $member['state'] === CollectionService::PENDING)),
            'approved' => array_values(array_filter($members, static fn (array $member): bool => $member['state'] === CollectionService::APPROVED)),
            'available' => $available,
            'userId' => $user->id,
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to($collection === null ? self::PATH . '/new' : self::PATH . '/' . $collection->id),
            'baseUrl' => AdminUrl::to(self::PATH),
            'storyUrl' => AdminUrl::to('stories'),
            'publicUrl' => $collection !== null && $collection->isPublic ? PublicUrl::collection($collection->slug) : '',
            'limits' => ['title' => CollectionService::TITLE_MAX, 'description' => CollectionService::DESCRIPTION_MAX, 'note' => CollectionService::NOTE_MAX],
        ], $user, $collection === null ? 'Add a collection' : 'Collection: ' . $collection->title, self::PATH, $this->flash->pull($request->cookies), $status);
    }

    private function deletePage(Request $request, User $user, Collection $collection, int $status, string $problem = ''): Response
    {
        $errors = $problem === '' ? [] : ['form' => $problem];

        return $this->view->render('collection-delete', [
            'collection' => $collection,
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH . '/' . $collection->id . '/delete'),
            'backUrl' => AdminUrl::to(self::PATH . '/' . $collection->id),
        ], $user, 'Delete collection', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
