<?php

/**
 * The writer's story screens: lists, the story form with its classification, publishing, bulk actions, deletion and preview.
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
use LumoraFiction\Core\Media\CoverImageStore;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\Chapter;
use LumoraFiction\Models\DownloadChoice;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\User;
use LumoraFiction\Models\WorkStatus;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ChapterService;
use LumoraFiction\Services\DownloadAccess;
use LumoraFiction\Services\DownloadSettings;
use LumoraFiction\Services\StoryPermissionException;
use LumoraFiction\Services\StoryPresenter;
use LumoraFiction\Services\StoryService;
use LumoraFiction\Services\StoryValidationException;
use LumoraFiction\Services\TaxonomyService;
use LumoraFiction\Services\UserService;

/**
 * Every action asks the story service, which decides ownership on the server. A story that is missing and
 * a story that belongs to someone else both come back as "not available", so an address cannot be used to
 * find out which drafts exist. A form that fails is shown again with what was typed.
 */
final class StoryController extends WriterController
{
    private const PATH = 'stories';

    private const PER_PAGE = 25;

    /** More names than this in one field is refused before any is looked up. */
    private const NAMES_PER_FIELD = 100;

    private const LANGUAGES = [
        'en' => 'English', 'fi' => 'Finnish', 'sv' => 'Swedish', 'de' => 'German', 'fr' => 'French', 'es' => 'Spanish',
        'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch', 'pl' => 'Polish', 'ru' => 'Russian', 'ja' => 'Japanese',
        'zh' => 'Chinese', 'ko' => 'Korean',
    ];

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly StoryService $stories,
        private readonly ChapterService $chapters,
        private readonly TaxonomyService $taxonomy,
        private readonly UserService $users,
        private readonly ThemeRenderer $themes,
        private readonly StoryPresenter $presenter,
        private readonly ?DownloadAccess $downloads = null,
        private readonly ?Configuration $config = null,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function index(array $params, Request $request): Response
    {
        return $this->listScreen($request, 'mine');
    }

    /**
     * @param array<string, string> $params
     */
    public function all(array $params, Request $request): Response
    {
        return $this->listScreen($request, 'all');
    }

    /**
     * @param array<string, string> $params
     */
    public function showCreate(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        return $user instanceof Response ? $user : $this->formPage($request, $user, null, $this->defaultValues(), $this->termState(null, null), [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH, self::PATH . '/new', $this->keptFields());

        if ($user instanceof Response) {
            return $user;
        }

        $values = $this->postedValues($request);
        $state = $this->termState($request, null);

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->formPage($request, $user, null, $values, $state, ['form' => self::STALE_FORM], 400);
        }

        try {
            $story = $this->stories->create($user, $values, $request->ip());
        } catch (StoryValidationException $exception) {
            return $this->formPage($request, $user, null, $values, $state, $exception->errors, 422);
        } catch (StoryPermissionException $exception) {
            return $this->missing($exception->getMessage());
        }

        try {
            $this->stories->classify($user, $story->id, $this->selection($state), $request->ip());
        } catch (StoryValidationException $exception) {
            // The story exists now, so the form continues as an edit of it rather than creating a second one.
            $this->flash->add('warning', 'The story was saved, but its classification was not. Please fix the problems below.');

            return $this->formPage($request, $user, $story, $values, $state, $exception->errors, 422);
        }

        try {
            $this->stories->applyCover($user, $story->id, $request->upload('cover'), $values['cover_alt'], $request->checked('cover_remove'), $request->ip(), $this->postedCrop($request));
        } catch (StoryValidationException $exception) {
            $this->flash->add('warning', 'The story was saved, but its cover was not. Please fix the problems below.');

            return $this->formPage($request, $user, $story, $values, $state, $exception->errors, 422);
        }

        $this->flash->add('success', 'The story was saved as a draft. You can add its chapters now.');

        return Response::redirect(AdminUrl::to(self::PATH . '/' . $story->id), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function showEdit(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $story = $this->storyFrom($user, $params);

        if ($story === null) {
            return $this->missing('That story is not available.');
        }

        return $this->formPage($request, $user, $story, $this->valuesOf($story), $this->termState(null, $story), [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(array $params, Request $request): Response
    {
        $id = $this->idFrom($params);
        $user = $this->writer($request, self::PATH, self::PATH . '/' . $id, $this->keptFields());

        if ($user instanceof Response) {
            return $user;
        }

        $story = $this->storyFrom($user, $params);

        if ($story === null) {
            return $this->missing('That story is not available.');
        }

        $values = $this->postedValues($request);
        $state = $this->termState($request, $story);

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->formPage($request, $user, $story, $values, $state, ['form' => self::STALE_FORM], 400);
        }

        try {
            $this->stories->update($user, $story->id, $values, $request->ip());
            $this->stories->classify($user, $story->id, $this->selection($state), $request->ip());
            $this->stories->applyCover($user, $story->id, $request->upload('cover'), $values['cover_alt'], $request->checked('cover_remove'), $request->ip(), $this->postedCrop($request));

            // A form that does not carry the field (an older page left open) leaves the choice as it was.
            $choice = DownloadChoice::tryFrom((string) $request->input('download_choice'));

            if ($choice !== null) {
                $this->downloads?->setChoice($user, $story->id, $choice, $request->ip());
            }
        } catch (StoryValidationException $exception) {
            return $this->formPage($request, $user, $story, $values, $state, $exception->errors, 422);
        } catch (StoryPermissionException $exception) {
            return $this->missing($exception->getMessage());
        }

        $this->flash->add('success', 'The story was saved.');

        return Response::redirect(AdminUrl::to(self::PATH . '/' . $story->id), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function publish(array $params, Request $request): Response
    {
        return $this->changeVisibility($params, $request, true);
    }

    /**
     * @param array<string, string> $params
     */
    public function unpublish(array $params, Request $request): Response
    {
        return $this->changeVisibility($params, $request, false);
    }

    /**
     * @param array<string, string> $params
     */
    public function showDelete(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $story = $this->storyFrom($user, $params);

        return $story === null ? $this->missing('That story is not available.') : $this->deletePage($request, $user, $story, [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $story = $this->storyFrom($user, $params);

        if ($story === null) {
            return $this->missing('That story is not available.');
        }

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->deletePage($request, $user, $story, ['form' => self::STALE_FORM], 400);
        }

        try {
            $this->stories->delete($user, $story->id, $request->ip());
        } catch (StoryPermissionException $exception) {
            return $this->missing($exception->getMessage());
        }

        $this->flash->add('success', '"' . $story->title . '" and its chapters were deleted.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * Moves the story to the trash. Nothing is lost, so there is nothing to confirm.
     *
     * @param array<string, string> $params
     */
    public function trash(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $story = $this->storyFrom($user, $params);

        if ($story === null) {
            return $this->missing('That story is not available.');
        }

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->formPage($request, $user, $story, $this->valuesOf($story), $this->termState(null, $story), ['form' => self::STALE_FORM], 400);
        }

        try {
            $this->stories->trash($user, $story->id, $request->ip());
        } catch (StoryPermissionException $exception) {
            return $this->missing($exception->getMessage());
        }

        $this->flash->add('success', '"' . $story->title . '" was moved to the trash. You can restore it from the Trash page.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * Makes a copy of the story, with its chapters as drafts, and opens it.
     *
     * @param array<string, string> $params
     */
    public function duplicate(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $story = $this->storyFrom($user, $params);

        if ($story === null) {
            return $this->missing('That story is not available.');
        }

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->formPage($request, $user, $story, $this->valuesOf($story), $this->termState(null, $story), ['form' => self::STALE_FORM], 400);
        }

        try {
            $copy = $this->stories->duplicate($user, $story->id, $request->ip());
        } catch (StoryPermissionException $exception) {
            return $this->missing($exception->getMessage());
        }

        $this->flash->add('success', 'This is a copy of "' . $story->title . '". Its chapters are drafts, and it has no cover picture.');

        return Response::redirect(AdminUrl::to(self::PATH . '/' . $copy->id), 303);
    }

    /**
     * Deleting asks for confirmation on a page of its own, so the first post only shows that page.
     *
     * @param array<string, string> $params
     */
    public function bulk(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $action = $request->input('bulk_action') ?? '';
        $ids = $this->postedIds($request);

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            $this->flash->add('error', self::STALE_FORM);

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        if (!in_array($action, ['publish', 'unpublish', 'trash', 'delete'], true)) {
            $this->flash->add('error', 'Choose what to do with the selected stories.');

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        if ($ids === []) {
            $this->flash->add('error', 'Tick at least one story first.');

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        if ($action === 'delete' && !$request->checked('confirmed')) {
            return $this->bulkDeletePage($request, $user, $ids);
        }

        try {
            $result = $this->stories->bulk($user, $action, $ids, $request->ip());
        } catch (StoryValidationException $exception) {
            $this->flash->add('error', implode(' ', $exception->errors));

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        $this->flash->add(
            $result['done'] > 0 ? 'success' : 'warning',
            $result['done'] . ($result['done'] === 1 ? ' story was ' : ' stories were ') . ['publish' => 'published or sent for approval', 'unpublish' => 'unpublished', 'trash' => 'moved to the trash', 'delete' => 'deleted'][$action] . '.'
            . ($result['skipped'] > 0 ? ' ' . $result['skipped'] . ' could not be changed (it may not be yours, or it still lacks required classification).' : ''),
        );

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function preview(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $story = $this->storyFrom($user, $params);

        if ($story === null) {
            return $this->missing('That story is not available.');
        }

        $data = $this->presenter->story(
            $story,
            $this->users->displayNames([$story->ownerId])[$story->ownerId] ?? '',
            $this->taxonomy->termsForStory($story->id),
            $this->chapters->listForWriter($user, $story->id),
            AdminUrl::to(self::PATH . '/' . $story->id . '/preview'),
            static fn (Chapter $chapter): string => AdminUrl::to('chapters/' . $chapter->id . '/preview'),
            true,
            null,
            '',
            static fn (Chapter $chapter): string => AdminUrl::to('chapters/' . $chapter->id),
        );

        // A preview is the page readers will get, so it goes through the active theme; it is private and never indexed.
        return $this->themes->render('story', $data, 200, [
            'title' => $story->title,
            'noindex' => true,
            'preview' => ['label' => strtolower($story->state->label()), 'edit_url' => AdminUrl::to(self::PATH . '/' . $story->id)],
        ])->withHeader('Cache-Control', 'private, no-store');
    }

    private function listScreen(Request $request, string $scope): Response
    {
        $path = $scope === 'all' ? self::PATH . '/all' : self::PATH;
        $user = $scope === 'all' ? $this->guard($request, Capability::EditAnyStory, $path) : $this->writer($request, $path);

        if ($user instanceof Response) {
            return $user;
        }

        $filters = [
            'scope' => $scope,
            'state' => PublicationState::tryFrom($request->queryValue('state') ?? '')?->value ?? '',
            'search' => $request->queryValue('q') ?? '',
        ];

        $sort = StoryService::writerSort($request->queryValue('sort'), $request->queryValue('dir'));
        $total = $this->stories->countForWriter($user, $filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) ($request->queryValue('page') ?? 1)));
        $stories = $this->stories->listForWriter($user, $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE, $sort);
        $ids = array_map(static fn (Story $story): int => $story->id, $stories);

        return $this->view->render('stories-list', [
            'stories' => $stories,
            'owners' => $scope === 'all' ? $this->users->displayNames(array_map(static fn (Story $story): int => $story->ownerId, $stories)) : [],
            'chapterCounts' => $this->chapters->countsForStories($ids),
            'sort' => $sort,
            'filters' => $filters,
            'scope' => $scope,
            'states' => PublicationState::cases(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'listUrl' => AdminUrl::to($path),
            'newUrl' => AdminUrl::to(self::PATH . '/new'),
            'storyUrl' => AdminUrl::to(self::PATH),
            'bulkUrl' => AdminUrl::to(self::PATH . '/bulk'),
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
        ], $user, $scope === 'all' ? 'All stories' : 'My stories', $path, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $params
     */
    private function changeVisibility(array $params, Request $request, bool $publish): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $story = $this->storyFrom($user, $params);

        if ($story === null) {
            return $this->missing('That story is not available.');
        }

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->formPage($request, $user, $story, $this->valuesOf($story), $this->termState(null, $story), ['form' => self::STALE_FORM], 400);
        }

        try {
            $result = $publish ? $this->stories->publish($user, $story->id, $request->ip()) : $this->stories->unpublish($user, $story->id, $request->ip());
        } catch (StoryValidationException $exception) {
            return $this->formPage($request, $user, $story, $this->valuesOf($story), $this->termState(null, $story), $exception->errors, 422);
        } catch (StoryPermissionException $exception) {
            return $this->missing($exception->getMessage());
        }

        $this->flash->add('success', match ($result->state) {
            PublicationState::Published => 'The story is published.',
            PublicationState::Pending => 'The story was sent for approval. It stays hidden from readers until it is approved.',
            PublicationState::Unpublished => 'The story is no longer visible to readers.',
            PublicationState::Draft => 'The story is a draft again.',
        });

        return Response::redirect(AdminUrl::to(self::PATH . '/' . $story->id), 303);
    }

    /**
     * @param array<string, string> $params
     */
    private function storyFrom(User $user, array $params): ?Story
    {
        $id = $this->idFrom($params);

        if ($id === 0) {
            return null;
        }

        try {
            return $this->stories->manageable($user, $id);
        } catch (StoryPermissionException) {
            return null;
        }
    }

    /**
     * @param array{title: string, summary: string, notes: string, disclaimer: string, language: string, work_status: string} $values
     * @param array<string, array{ids: list<int>, text: string}> $state
     * @param array<string, string> $errors
     */
    private function formPage(Request $request, User $user, ?Story $story, array $values, array $state, array $errors, int $status): Response
    {
        $required = array_map(static fn (TermType $type): string => $type->value, $this->taxonomy->requiredTypes());
        $fields = [];

        foreach ($this->taxonomy->enabledTypes() as $type) {
            $single = $type->isSingleChoice();
            $names = !$single && $type->writersMayPropose();
            $options = $names ? [] : $this->taxonomy->list($type, ['visible' => true], 200);

            // A value that was hidden after this story chose it stays on the list, so saving the form does not drop it.
            if (!$names) {
                $listed = array_map(static fn (Term $term): int => $term->id, $options);

                foreach ($state[$type->value]['ids'] ?? [] as $chosen) {
                    $kept = in_array($chosen, $listed, true) ? null : $this->taxonomy->find($chosen);

                    if ($kept !== null && $kept->type === $type) {
                        $options[] = $kept;
                    }
                }
            }

            $fields[] = [
                'type' => $type,
                'style' => $single ? 'single' : ($names ? 'names' : 'choices'),
                'options' => $options,
                'ids' => $state[$type->value]['ids'] ?? [],
                'text' => $state[$type->value]['text'] ?? '',
                // A kind with nothing to choose from is never enforced (see TaxonomyService::missingRequired()), so it is not marked either.
                'required' => in_array($type->value, $required, true) && ($names || $options !== []),
            ];
        }

        $languages = self::LANGUAGES;

        if (!isset($languages[$values['language']])) {
            $languages[$values['language']] = strtoupper($values['language']);
        }

        $trusted = $story !== null && $this->stories->publishesDirectly($user, $story);
        $base = $story === null ? AdminUrl::to(self::PATH) : AdminUrl::to(self::PATH . '/' . $story->id);

        return $this->view->render('story-form', [
            'story' => $story,
            'values' => $values,
            'fields' => $fields,
            'languages' => $languages,
            'workStatuses' => $this->stories->offeredStatuses($story),
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
            'action' => $story === null ? AdminUrl::to(self::PATH . '/new') : $base,
            'listUrl' => AdminUrl::to(self::PATH),
            'trusted' => $trusted,
            'urls' => $story === null ? [] : [
                'chapters' => $base . '/chapters',
                'addChapter' => $base . '/chapters/new',
                'preview' => $base . '/preview',
                'delete' => $base . '/delete',
                'trash' => $base . '/trash',
                'duplicate' => $base . '/duplicate',
                'publish' => $base . '/publish',
                'unpublish' => $base . '/unpublish',
            ],
            'chapterCount' => $story === null ? 0 : ($this->chapters->countsForStories([$story->id])[$story->id]['total'] ?? 0),
            'panels' => $story === null ? [] : $this->pluginPanels($story, $user),
            'downloadChoices' => $story === null ? [] : $this->downloadChoices($story),
            'authorAllowsDownloads' => $story !== null && $this->downloads !== null && $this->downloads->authorAllows($story->ownerId),
            'downloadsSiteOn' => $this->config !== null && DownloadSettings::enabled($this->config),
            'authorProfileUrl' => $story !== null && $story->ownerId === $user->id ? AdminUrl::to('profile') : '',
            'cover' => $story === null ? ['thumb' => '', 'full' => '', 'alt' => ''] : $this->presenter->cover($story),
            'coverAvailable' => CoverImageStore::isAvailable(),
            'coverLimit' => (string) round(CoverImageStore::limitBytes() / 1048576, 1),
            'limits' => [
                'title' => StoryService::TITLE_MAX,
                'summary' => StoryService::SUMMARY_MAX,
                'notes' => StoryService::NOTES_MAX,
                'disclaimer' => StoryService::DISCLAIMER_MAX,
                'cover_alt' => StoryService::COVER_ALT_MAX,
            ],
        ], $user, $story === null ? 'New story' : 'Edit story', $story === null ? self::PATH . '/new' : self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @return list<array{value: string, label: string, selected: bool}>
     */
    private function downloadChoices(Story $story): array
    {
        if ($this->downloads === null) {
            return [];
        }

        $current = $this->downloads->choiceFor($story->id);

        return array_map(
            static fn (DownloadChoice $choice): array => ['value' => $choice->value, 'label' => $choice->label(), 'selected' => $choice === $current],
            DownloadChoice::cases(),
        );
    }

    /**
     * @return list<array{title: string, html: string}>
     */
    private function pluginPanels(Story $story, User $user): array
    {
        $panels = [];

        // The person is already known to manage this story, so a plugin only has to decide what to show.
        foreach ((array) apply_filters('admin_story_panels', [], $story, $user) as $panel) {
            if (is_array($panel) && is_string($panel['title'] ?? null) && is_string($panel['html'] ?? null)) {
                $panels[] = ['title' => $panel['title'], 'html' => $panel['html']];
            }
        }

        return $panels;
    }

    /**
     * @param array<string, string> $errors
     */
    private function deletePage(Request $request, User $user, Story $story, array $errors, int $status): Response
    {
        return $this->view->render('story-delete', [
            'story' => $story,
            'chapterCount' => $this->chapters->countsForStories([$story->id])[$story->id]['total'] ?? 0,
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
            'action' => AdminUrl::to(self::PATH . '/' . $story->id . '/delete'),
            'backUrl' => AdminUrl::to(self::PATH . '/' . $story->id),
        ], $user, 'Delete ' . $story->title, self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @param list<int> $ids
     */
    private function bulkDeletePage(Request $request, User $user, array $ids): Response
    {
        // Only stories the caller may change are listed, so the page never confirms that anyone else's exist.
        $stories = [];

        foreach ($ids as $id) {
            try {
                $stories[] = $this->stories->manageable($user, $id);
            } catch (StoryPermissionException) {
                continue;
            }
        }

        return $this->view->render('stories-bulk-delete', [
            'stories' => $stories,
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
            'action' => AdminUrl::to(self::PATH . '/bulk'),
            'backUrl' => AdminUrl::to(self::PATH),
        ], $user, 'Delete stories', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @return list<int>
     */
    private function postedIds(Request $request): array
    {
        $ids = [];

        foreach ($request->inputList('ids') as $value) {
            if (preg_match('/^[1-9][0-9]{0,9}\z/', $value) === 1) {
                $ids[] = (int) $value;
            }
        }

        return array_slice(array_values(array_unique($ids)), 0, 100);
    }

    /**
     * @return array{title: string, summary: string, notes: string, disclaimer: string, language: string, work_status: string}
     */
    private function defaultValues(): array
    {
        return ['title' => '', 'summary' => '', 'notes' => '', 'disclaimer' => '', 'language' => 'en', 'work_status' => WorkStatus::Ongoing->value, 'cover_alt' => ''];
    }

    /**
     * @return array{title: string, summary: string, notes: string, disclaimer: string, language: string, work_status: string}
     */
    private function valuesOf(Story $story): array
    {
        return [
            'title' => $story->title,
            'summary' => $story->summary,
            'notes' => $story->notes,
            'disclaimer' => $story->disclaimer,
            'language' => $story->language,
            'work_status' => $story->workStatus->value,
            'cover_alt' => $story->coverAlt,
        ];
    }

    /**
     * A field the form did not send is read as empty, never as "keep the old value": the form always sends
     * every field, so a missing one means the request was not made by this form.
     *
     * @return array{title: string, summary: string, notes: string, disclaimer: string, language: string, work_status: string}
     */
    private function postedValues(Request $request): array
    {
        $values = $this->defaultValues();

        foreach (array_keys($values) as $field) {
            $values[$field] = $request->input($field) ?? $values[$field];
        }

        return $values;
    }

    /**
     * What each classification field shows: the ticked or chosen term ids, and the typed names. Taken from the
     * posted form when there is one, otherwise from what the story already carries.
     *
     * @return array<string, array{ids: list<int>, text: string}>
     */
    private function termState(?Request $request, ?Story $story): array
    {
        $current = $story === null ? [] : $this->taxonomy->termsForStory($story->id);
        $state = [];

        foreach ($this->taxonomy->enabledTypes() as $type) {
            $kind = $type->value;

            if ($request === null || !$request->isPost()) {
                $terms = $current[$kind] ?? [];
                $state[$kind] = [
                    'ids' => array_map(static fn (Term $term): int => $term->id, $terms),
                    'text' => implode(', ', array_map(static fn (Term $term): string => $term->name, $terms)),
                ];

                continue;
            }

            $raw = $type->isSingleChoice() ? array_filter([$request->input('term_' . $kind)], static fn (?string $value): bool => $value !== null && $value !== '') : $request->inputList('terms_' . $kind);
            $ids = [];

            foreach ($raw as $value) {
                if (preg_match('/^[1-9][0-9]{0,9}\z/', $value) === 1) {
                    $ids[] = (int) $value;
                }
            }

            $state[$kind] = ['ids' => array_values(array_unique($ids)), 'text' => $request->input('names_' . $kind) ?? ''];
        }

        return $state;
    }

    /**
     * @param array<string, array{ids: list<int>, text: string}> $state
     * @return array<string, list<int|string>>
     */
    private function selection(array $state): array
    {
        $selection = [];

        foreach ($this->taxonomy->enabledTypes() as $type) {
            $entry = $state[$type->value] ?? ['ids' => [], 'text' => ''];

            if ($type->isSingleChoice() || !$type->writersMayPropose()) {
                $selection[$type->value] = $entry['ids'];

                continue;
            }

            $names = [];

            foreach (preg_split('/[,\n]+/', $entry['text']) ?: [] as $name) {
                $name = trim($name);

                if ($name !== '') {
                    $names[] = $name;
                }
            }

            $selection[$type->value] = array_slice(array_values(array_unique($names)), 0, self::NAMES_PER_FIELD);
        }

        return $selection;
    }

    /**
     * The area of a new cover the writer chose to keep, or null for the whole picture.
     *
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    private function postedCrop(Request $request): ?array
    {
        return CoverImageStore::parseCrop($request->input('crop_x'), $request->input('crop_y'), $request->input('crop_w'), $request->input('crop_h'));
    }

    /**
     * @return list<string> the form fields whose text is handed back if the session has ended
     */
    private function keptFields(): array
    {
        $fields = ['title', 'summary', 'notes', 'disclaimer', 'language', 'work_status', 'cover_alt'];

        foreach (TermType::cases() as $type) {
            array_push($fields, 'term_' . $type->value, 'terms_' . $type->value, 'names_' . $type->value);
        }

        return $fields;
    }
}
