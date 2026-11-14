<?php

/**
 * The admin screens for series: list, add, edit, order the stories, delete.
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
use LumoraFiction\Models\Series;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\User;
use LumoraFiction\Models\WorkStatus;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\GroupingException;
use LumoraFiction\Services\GroupingSettings;
use LumoraFiction\Services\SeriesService;
use LumoraFiction\Services\StoryService;

/**
 * Permission is the right to write stories, checked here on every route and again inside the service, which also checks
 * that a person manages the series and the story. A series that does not exist and one the person may not touch are
 * answered the same way. While series are switched off every screen is refused, and nothing stored is lost.
 */
final class SeriesController extends WriterController
{
    private const PATH = 'series';

    private const TOKEN = 'admin_series';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly SeriesService $series,
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

        return $this->view->render('series-list', [
            'rows' => $this->series->listFor($user, $all),
            'all' => $all,
            'mayListAll' => $user->can(Capability::EditAnyStory),
            'newUrl' => AdminUrl::to(self::PATH . '/new'),
            'baseUrl' => AdminUrl::to(self::PATH),
        ], $user, 'Series', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $params
     */
    public function showCreate(array $params, Request $request): Response
    {
        $user = $this->open($request, self::PATH);

        return $user instanceof Response ? $user : $this->formPage($request, $user, null, ['title' => '', 'summary' => '', 'work_status' => WorkStatus::Ongoing->value], [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(array $params, Request $request): Response
    {
        $user = $this->open($request, self::PATH, self::PATH . '/new', ['title', 'summary']);

        if ($user instanceof Response) {
            return $user;
        }

        $values = $this->posted($request);

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->formPage($request, $user, null, $values, ['form' => self::STALE_FORM], 400);
        }

        try {
            $series = $this->series->create($user, $values, $request->ip());
        } catch (GroupingException $exception) {
            return $this->formPage($request, $user, null, $values, $exception->errors !== [] ? $exception->errors : ['form' => $exception->getMessage()], 422);
        }

        $this->flash->add('success', 'Series added. Now add its stories.');

        return Response::redirect(AdminUrl::to(self::PATH . '/' . $series->id), 303);
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

        $series = $this->owned($user, $params);

        if ($series === null) {
            return $this->missing('That series is not available.', self::PATH);
        }

        return $this->formPage($request, $user, $series, ['title' => $series->title, 'summary' => $series->summary, 'work_status' => $series->workStatus->value], [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(array $params, Request $request): Response
    {
        $user = $this->open($request, self::PATH, self::PATH . '/' . $this->idFrom($params), ['title', 'summary']);

        if ($user instanceof Response) {
            return $user;
        }

        $series = $this->owned($user, $params);

        if ($series === null) {
            return $this->missing('That series is not available.', self::PATH);
        }

        $values = $this->posted($request);

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->formPage($request, $user, $series, $values, ['form' => self::STALE_FORM], 400);
        }

        try {
            $this->series->update($user, $series->id, $values, $request->ip());
        } catch (GroupingException $exception) {
            return $this->formPage($request, $user, $series, $values, $exception->errors !== [] ? $exception->errors : ['form' => $exception->getMessage()], 422);
        }

        $this->flash->add('success', 'Series saved.');

        return Response::redirect(AdminUrl::to(self::PATH . '/' . $series->id), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function addStory(array $params, Request $request): Response
    {
        return $this->act($params, $request, function (User $user, Series $series) use ($request): string {
            $storyId = (int) $request->input('story_id');

            if ($storyId < 1) {
                throw new GroupingException('Choose a story to add.');
            }

            $this->series->addStory($user, $series->id, $storyId, null, $request->ip());

            return 'Story added to the series.';
        });
    }

    /**
     * Takes the ticked stories out of the series. The stories themselves are untouched, so nothing is asked first.
     *
     * @param array<string, string> $params
     */
    public function bulk(array $params, Request $request): Response
    {
        $user = $this->open($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $series = $this->owned($user, $params);

        if ($series === null) {
            return $this->missing('That series is not available.', self::PATH);
        }

        $back = AdminUrl::to(self::PATH . '/' . $series->id);
        $input = $this->bulkInput($request, self::TOKEN, ['remove'], 'stories', $back);

        if ($input instanceof Response) {
            return $input;
        }

        try {
            $result = $this->series->removeStories($user, $series->id, $input['ids'], $request->ip());
        } catch (GroupingException $exception) {
            return $this->missing($exception->getMessage(), self::PATH);
        }

        $this->flash->add($result->flashType(), $result->summary('taken out of the series', 'story', 'stories'));

        return Response::redirect($back, 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function removeStory(array $params, Request $request): Response
    {
        return $this->act($params, $request, function (User $user, Series $series) use ($params, $request): string {
            $this->series->removeStory($user, $series->id, $this->number($params['story'] ?? ''), $request->ip());

            return 'Story taken out of the series. The story itself is untouched.';
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function move(array $params, Request $request): Response
    {
        return $this->act($params, $request, function (User $user, Series $series) use ($params, $request): string {
            $this->series->move($user, $series->id, $this->number($params['story'] ?? ''), (string) $request->input('direction'), $request->ip());

            return 'Order saved.';
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

        $series = $this->owned($user, $params);

        if ($series === null) {
            return $this->missing('That series is not available.', self::PATH);
        }

        return $this->deletePage($request, $user, $series, 200);
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

        $series = $this->owned($user, $params);

        if ($series === null) {
            return $this->missing('That series is not available.', self::PATH);
        }

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->deletePage($request, $user, $series, 400, self::STALE_FORM);
        }

        try {
            $this->series->delete($user, $series->id, $request->ip());
        } catch (GroupingException $exception) {
            return $this->missing($exception->getMessage(), self::PATH);
        }

        $this->flash->add('success', 'Series deleted. Its stories were not touched.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * The same steps for every button on the edit screen: check the person and the token, run the change, say what happened.
     *
     * @param array<string, string> $params
     * @param callable(User, Series): string $change returns the message to show when it worked
     */
    private function act(array $params, Request $request, callable $change): Response
    {
        $user = $this->open($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $series = $this->owned($user, $params);

        if ($series === null) {
            return $this->missing('That series is not available.', self::PATH);
        }

        $back = Response::redirect(AdminUrl::to(self::PATH . '/' . $series->id), 303);

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);

            return $back;
        }

        try {
            $this->flash->add('success', $change($user, $series));
        } catch (GroupingException $exception) {
            $this->flash->add('error', $exception->getMessage());
        }

        return $back;
    }

    /**
     * The writer, or the response that refuses them: a guest, someone who may not write, or any visitor while series are off.
     *
     * @param list<string> $keep
     */
    private function open(Request $request, string $currentPath, string $returnPath = '', array $keep = []): User|Response
    {
        $user = $this->writer($request, $currentPath, $returnPath, $keep);

        if ($user instanceof Response) {
            return $user;
        }

        if (!GroupingSettings::seriesEnabled($this->config)) {
            return $this->missing('Series are switched off on this site.', 'stories');
        }

        return $user;
    }

    /**
     * @param array<string, string> $params
     */
    private function owned(User $user, array $params): ?Series
    {
        $series = $this->series->find($this->idFrom($params));

        return $series !== null && $this->series->canManage($user, $series) ? $series : null;
    }

    private function number(string $value): int
    {
        return preg_match('/^[1-9][0-9]{0,9}\z/', $value) === 1 ? (int) $value : 0;
    }

    /**
     * @return array{title: string, summary: string, work_status: string}
     */
    private function posted(Request $request): array
    {
        return [
            'title' => (string) $request->input('title'),
            'summary' => (string) $request->input('summary'),
            'work_status' => (string) $request->input('work_status'),
        ];
    }

    /**
     * @param array{title: string, summary: string, work_status: string} $values
     * @param array<string, string> $errors
     */
    private function formPage(Request $request, User $user, ?Series $series, array $values, array $errors, int $status): Response
    {
        $members = $series === null ? [] : $this->series->members($series->id);
        $inSeries = array_flip(array_map(static fn (Story $story): int => $story->id, $members));
        $available = [];

        if ($series !== null) {
            $stories = $this->stories->listForOwner($series->ownerId);
            $taken = $this->series->seriesOfStories(array_map(static fn (Story $story): int => $story->id, $stories));

            foreach ($stories as $story) {
                if (!isset($inSeries[$story->id]) && !isset($taken[$story->id])) {
                    $available[] = $story;
                }
            }
        }

        return $this->view->render('series-form', [
            'series' => $series,
            'values' => $values,
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'members' => $members,
            'available' => $available,
            'statuses' => WorkStatus::cases(),
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to($series === null ? self::PATH . '/new' : self::PATH . '/' . $series->id),
            'baseUrl' => AdminUrl::to(self::PATH),
            'storyUrl' => AdminUrl::to('stories'),
            'publicUrl' => $series !== null && $this->series->publicCount($series->id) > 0 ? PublicUrl::series($series->slug) : '',
            'limits' => ['title' => SeriesService::TITLE_MAX, 'summary' => SeriesService::SUMMARY_MAX],
        ], $user, $series === null ? 'Add a series' : 'Series: ' . $series->title, self::PATH, $this->flash->pull($request->cookies), $status);
    }

    private function deletePage(Request $request, User $user, Series $series, int $status, string $problem = ''): Response
    {
        $errors = $problem === '' ? [] : ['form' => $problem];

        return $this->view->render('series-delete', [
            'series' => $series,
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH . '/' . $series->id . '/delete'),
            'backUrl' => AdminUrl::to(self::PATH . '/' . $series->id),
        ], $user, 'Delete series', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
