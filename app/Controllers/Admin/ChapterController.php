<?php

/**
 * The writer's chapter screens: the chapter list with reordering, the chapter form with the text editor, deletion and preview.
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
use LumoraFiction\Core\Admin\ContentEditor;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Core\Theme\SafeHtml;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Models\Chapter;
use LumoraFiction\Models\ChapterRevision;
use LumoraFiction\Models\ContentFormat;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ChapterService;
use LumoraFiction\Services\ContentRenderer;
use LumoraFiction\Services\StoryPermissionException;
use LumoraFiction\Services\StoryPresenter;
use LumoraFiction\Services\StoryService;
use LumoraFiction\Services\StoryValidationException;
use LumoraFiction\Services\UserService;

/**
 * Permission follows the story, and the services decide it. A chapter or story that does not exist and one
 * that belongs to someone else are answered the same way. A save that fails shows the form again with the
 * text exactly as it was typed.
 */
final class ChapterController extends WriterController
{
    private const PATH = 'stories';

    /** @var list<string> */
    private const KEPT = ['title', 'content', 'content_format', 'notes'];

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly StoryService $stories,
        private readonly ChapterService $chapters,
        private readonly ContentEditor $editor,
        private readonly ContentRenderer $renderer,
        private readonly UserService $users,
        private readonly ThemeRenderer $themes,
        private readonly StoryPresenter $presenter,
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

        $story = $this->storyFrom($user, $params);

        return $story === null ? $this->missing('That story is not available.') : $this->listPage($request, $user, $story, [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function move(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $story = $this->storyFrom($user, $params);

        if ($story === null) {
            return $this->missing('That story is not available.');
        }

        $back = Response::redirect(AdminUrl::to(self::PATH . '/' . $story->id . '/chapters'), 303);

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->listPage($request, $user, $story, ['form' => self::STALE_FORM], 400);
        }

        $chapterId = preg_match('/^[1-9][0-9]{0,9}\z/', $request->input('chapter') ?? '') === 1 ? (int) $request->input('chapter') : 0;
        $chapter = $chapterId === 0 ? null : $this->chapters->find($chapterId);

        // A chapter of another story is refused here, so the address of this story cannot be used to move it.
        if ($chapter === null || $chapter->storyId !== $story->id) {
            return $this->listPage($request, $user, $story, ['form' => 'That chapter is not part of this story. Reload the page and try again.'], 422);
        }

        try {
            $this->chapters->move($user, $chapter->id, $request->input('direction') ?? '', $request->ip());
        } catch (StoryValidationException $exception) {
            return $this->listPage($request, $user, $story, $exception->errors + ['form' => 'The chapters could not be moved.'], 422);
        } catch (StoryPermissionException $exception) {
            return $this->missing($exception->getMessage());
        }

        $this->flash->add('success', 'Chapter order saved.');

        return $back;
    }

    /**
     * @param array<string, string> $params
     */
    public function showCreate(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $story = $this->storyFrom($user, $params);

        if ($story === null) {
            return $this->missing('That story is not available.');
        }

        return $this->formPage($request, $user, $story, null, ['title' => '', 'content' => '', 'content_format' => '', 'notes' => ''], [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH, self::PATH . '/' . $this->idFrom($params) . '/chapters/new', self::KEPT);

        if ($user instanceof Response) {
            return $user;
        }

        $story = $this->storyFrom($user, $params);

        if ($story === null) {
            return $this->missing('That story is not available.');
        }

        $values = $this->postedValues($request);

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->formPage($request, $user, $story, null, $values, ['form' => self::STALE_FORM], 400);
        }

        try {
            $chapter = $this->chapters->add($user, $story->id, $this->serviceInput($values), $request->ip());
            $chapter = $this->applyAction($user, $chapter, $request);
        } catch (StoryValidationException $exception) {
            return $this->formPage($request, $user, $story, null, $values, $exception->errors, 422);
        } catch (StoryPermissionException $exception) {
            return $this->missing($exception->getMessage());
        }

        $this->afterSave($request, $story, $chapter);

        return Response::redirect(AdminUrl::to('chapters/' . $chapter->id), 303);
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

        $chapter = $this->chapterFrom($user, $params);

        if ($chapter === null) {
            return $this->missing('That chapter is not available.');
        }

        $story = $this->stories->find($chapter->storyId);

        return $story === null ? $this->missing('That story is not available.') : $this->formPage($request, $user, $story, $chapter, $this->valuesOf($chapter), [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH, 'chapters/' . $this->idFrom($params), self::KEPT);

        if ($user instanceof Response) {
            return $user;
        }

        $chapter = $this->chapterFrom($user, $params);
        $story = $chapter === null ? null : $this->stories->find($chapter->storyId);

        if ($chapter === null || $story === null) {
            return $this->missing('That chapter is not available.');
        }

        $values = $this->postedValues($request);

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->formPage($request, $user, $story, $chapter, $values, ['form' => self::STALE_FORM], 400);
        }

        try {
            $chapter = $this->chapters->update($user, $chapter->id, $this->serviceInput($values), $request->ip());
            $chapter = $this->applyAction($user, $chapter, $request);
        } catch (StoryValidationException $exception) {
            return $this->formPage($request, $user, $story, $chapter, $values, $exception->errors, 422);
        } catch (StoryPermissionException $exception) {
            return $this->missing($exception->getMessage());
        }

        $this->afterSave($request, $story, $chapter);

        return Response::redirect(AdminUrl::to('chapters/' . $chapter->id), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function publish(array $params, Request $request): Response
    {
        return $this->changeState($params, $request, true);
    }

    /**
     * @param array<string, string> $params
     */
    public function unpublish(array $params, Request $request): Response
    {
        return $this->changeState($params, $request, false);
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

        $chapter = $this->chapterFrom($user, $params);
        $story = $chapter === null ? null : $this->stories->find($chapter->storyId);

        return $chapter === null || $story === null ? $this->missing('That chapter is not available.') : $this->deletePage($request, $user, $story, $chapter, [], 200);
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

        $chapter = $this->chapterFrom($user, $params);
        $story = $chapter === null ? null : $this->stories->find($chapter->storyId);

        if ($chapter === null || $story === null) {
            return $this->missing('That chapter is not available.');
        }

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->deletePage($request, $user, $story, $chapter, ['form' => self::STALE_FORM], 400);
        }

        try {
            $this->chapters->delete($user, $chapter->id, $request->ip());
        } catch (StoryPermissionException $exception) {
            return $this->missing($exception->getMessage());
        }

        $this->flash->add('success', 'The chapter was deleted.');

        return Response::redirect(AdminUrl::to(self::PATH . '/' . $story->id . '/chapters'), 303);
    }

    /**
     * Moves the chapter to the trash. Nothing is lost, so there is nothing to confirm.
     *
     * @param array<string, string> $params
     */
    public function trash(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $chapter = $this->chapterFrom($user, $params);
        $story = $chapter === null ? null : $this->stories->find($chapter->storyId);

        if ($chapter === null || $story === null) {
            return $this->missing('That chapter is not available.');
        }

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->formPage($request, $user, $story, $chapter, $this->valuesOf($chapter), ['form' => self::STALE_FORM], 400);
        }

        try {
            $this->chapters->trash($user, $chapter->id, $request->ip());
        } catch (StoryPermissionException $exception) {
            return $this->missing($exception->getMessage());
        }

        $this->flash->add('success', 'The chapter was moved to the trash. You can restore it from the Trash page.');

        return Response::redirect(AdminUrl::to(self::PATH . '/' . $story->id . '/chapters'), 303);
    }

    /**
     * The earlier versions of a chapter.
     *
     * @param array<string, string> $params
     */
    public function revisions(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $chapter = $this->chapterFrom($user, $params);

        if ($chapter === null) {
            return $this->missing('That chapter is not available.');
        }

        return $this->view->render('chapter-revisions', [
            'chapter' => $chapter,
            'revisions' => $this->chapters->revisions($user, $chapter->id),
            'authors' => $this->users->displayNames(array_map(static fn (ChapterRevision $revision): int => $revision->createdBy ?? 0, $this->chapters->revisions($user, $chapter->id))),
            'baseUrl' => AdminUrl::to('chapters/' . $chapter->id . '/revisions'),
            'backUrl' => AdminUrl::to('chapters/' . $chapter->id),
        ], $user, 'Earlier versions of chapter ' . $chapter->number, self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * One earlier version, shown as it would read, with a button to go back to it.
     *
     * @param array<string, string> $params
     */
    public function revision(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $chapter = $this->chapterFrom($user, $params);
        $revisionId = ctype_digit($params['revision'] ?? '') ? (int) $params['revision'] : 0;

        if ($chapter === null || $revisionId < 1) {
            return $this->missing('That chapter is not available.');
        }

        try {
            $revision = $this->chapters->revision($user, $chapter->id, $revisionId);
        } catch (StoryPermissionException $exception) {
            return $this->missing($exception->getMessage());
        }

        return $this->view->render('chapter-revision', [
            'chapter' => $chapter,
            'revision' => $revision,
            // Rendered through the same sanitiser as the live chapter, so an old version can never carry anything a new one cannot.
            'body' => $this->renderer->render($revision->content, $revision->format),
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
            'action' => AdminUrl::to('chapters/' . $chapter->id . '/revisions/' . $revision->id . '/restore'),
            'listUrl' => AdminUrl::to('chapters/' . $chapter->id . '/revisions'),
        ], $user, 'An earlier version of chapter ' . $chapter->number, self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * Goes back to an earlier version.
     *
     * @param array<string, string> $params
     */
    public function restoreRevision(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $chapter = $this->chapterFrom($user, $params);
        $revisionId = ctype_digit($params['revision'] ?? '') ? (int) $params['revision'] : 0;

        if ($chapter === null || $revisionId < 1) {
            return $this->missing('That chapter is not available.');
        }

        $back = Response::redirect(AdminUrl::to('chapters/' . $chapter->id . '/revisions'), 303);

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            $this->flash->add('error', self::STALE_FORM);

            return $back;
        }

        try {
            $this->chapters->restoreRevision($user, $chapter->id, $revisionId, $request->ip());
        } catch (StoryPermissionException $exception) {
            return $this->missing($exception->getMessage());
        }

        $this->flash->add('success', 'The chapter now has the text of that earlier version. The text it had before is kept as another earlier version.');

        return Response::redirect(AdminUrl::to('chapters/' . $chapter->id), 303);
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

        $chapter = $this->chapterFrom($user, $params);
        $story = $chapter === null ? null : $this->stories->find($chapter->storyId);

        if ($chapter === null || $story === null) {
            return $this->missing('That chapter is not available.');
        }

        $previous = null;
        $next = null;

        foreach ($this->chapters->listForWriter($user, $story->id) as $other) {
            if ($other->number === $chapter->number - 1) {
                $previous = $other;
            } elseif ($other->number === $chapter->number + 1) {
                $next = $other;
            }
        }

        $data = $this->presenter->chapter(
            $story,
            $this->users->displayNames([$story->ownerId])[$story->ownerId] ?? '',
            $chapter,
            new SafeHtml($this->renderer->render($chapter->content, $chapter->format)),
            $previous,
            $next,
            AdminUrl::to(self::PATH . '/' . $story->id . '/preview'),
            static fn (Chapter $other): string => AdminUrl::to('chapters/' . $other->id . '/preview'),
        );

        return $this->themes->render('chapter', $data, 200, [
            'title' => $this->presenter->chapterTitle($chapter) . ' — ' . $story->title,
            'noindex' => true,
            'preview' => ['label' => strtolower($chapter->state->label()), 'edit_url' => AdminUrl::to('chapters/' . $chapter->id)],
        ])->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @param array<string, string> $params
     */
    private function changeState(array $params, Request $request, bool $publish): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $chapter = $this->chapterFrom($user, $params);

        if ($chapter === null) {
            return $this->missing('That chapter is not available.');
        }

        $back = Response::redirect(AdminUrl::to(self::PATH . '/' . $chapter->storyId . '/chapters'), 303);

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            $this->flash->add('error', self::STALE_FORM);

            return $back;
        }

        try {
            $publish ? $this->chapters->publish($user, $chapter->id, $request->ip()) : $this->chapters->unpublish($user, $chapter->id, $request->ip());
        } catch (StoryPermissionException $exception) {
            return $this->missing($exception->getMessage());
        }

        $this->flash->add('success', $publish ? 'The chapter is published.' : 'The chapter is no longer visible to readers.');

        return $back;
    }

    /**
     * Carries out the button the writer pressed after the text has been saved.
     *
     * @throws StoryPermissionException
     */
    private function applyAction(User $user, Chapter $chapter, Request $request): Chapter
    {
        return match ($request->input('action')) {
            'publish' => $this->chapters->publish($user, $chapter->id, $request->ip()),
            'unpublish' => $this->chapters->unpublish($user, $chapter->id, $request->ip()),
            default => $chapter,
        };
    }

    private function afterSave(Request $request, Story $story, Chapter $chapter): void
    {
        $action = $request->input('action');

        $this->flash->add('success', match ($action) {
            'publish' => 'The chapter is published.',
            'unpublish' => 'The chapter was saved and is no longer visible to readers.',
            default => $chapter->state === PublicationState::Draft ? 'The draft was saved.' : 'The chapter was saved.',
        });

        // A published chapter stays invisible until its story is published too, which is easy to forget.
        if ($action === 'publish' && $story->state !== PublicationState::Published) {
            $this->flash->add('info', 'The story itself is still ' . strtolower($story->state->label()) . ', so readers cannot see this chapter yet.');
        }
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
     * @param array<string, string> $params
     */
    private function chapterFrom(User $user, array $params): ?Chapter
    {
        $id = $this->idFrom($params);

        if ($id === 0) {
            return null;
        }

        try {
            return $this->chapters->manageable($user, $id);
        } catch (StoryPermissionException) {
            return null;
        }
    }

    /**
     * @param array{title: string, content: string, content_format: string, notes: string} $values
     * @param array<string, string> $errors
     */
    private function formPage(Request $request, User $user, Story $story, ?Chapter $chapter, array $values, array $errors, int $status): Response
    {
        $stored = ContentFormat::tryFrom($values['content_format']) ?? $chapter?->format;
        $state = $chapter?->state ?? PublicationState::Draft;

        $actions = match ($state) {
            PublicationState::Published => [
                ['name' => 'action', 'value' => 'save', 'label' => 'Save changes', 'primary' => true],
                ['name' => 'action', 'value' => 'unpublish', 'label' => 'Unpublish'],
            ],
            PublicationState::Unpublished => [
                ['name' => 'action', 'value' => 'save', 'label' => 'Save changes'],
                ['name' => 'action', 'value' => 'publish', 'label' => 'Publish again', 'primary' => true],
            ],
            default => [
                ['name' => 'action', 'value' => 'save', 'label' => 'Save draft'],
                ['name' => 'action', 'value' => 'publish', 'label' => 'Publish', 'primary' => true],
            ],
        };

        $base = AdminUrl::to(self::PATH . '/' . $story->id);

        return $this->view->render('chapter-form', [
            'story' => $story,
            'chapter' => $chapter,
            'values' => $values,
            'editor' => $this->editor->render($user, 'content', $values['content'], $stored, ['label' => 'Text', 'id' => 'content', 'actions' => $actions]),
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
            'action' => $chapter === null ? $base . '/chapters/new' : AdminUrl::to('chapters/' . $chapter->id),
            'listUrl' => $base . '/chapters',
            'urls' => $chapter === null ? [] : [
                'preview' => AdminUrl::to('chapters/' . $chapter->id . '/preview'),
                'delete' => AdminUrl::to('chapters/' . $chapter->id . '/delete'),
                'trash' => AdminUrl::to('chapters/' . $chapter->id . '/trash'),
                'revisions' => AdminUrl::to('chapters/' . $chapter->id . '/revisions'),
            ],
            'revisionCount' => $chapter === null ? 0 : $this->chapters->countRevisions($chapter->id),
            'limits' => ['title' => ChapterService::TITLE_MAX, 'notes' => ChapterService::NOTES_MAX],
        ], $user, $chapter === null ? 'New chapter' : 'Edit chapter', self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @param array<string, string> $errors
     */
    private function listPage(Request $request, User $user, Story $story, array $errors, int $status): Response
    {
        $base = AdminUrl::to(self::PATH . '/' . $story->id);

        return $this->view->render('chapters-list', [
            'story' => $story,
            'chapters' => $this->chapters->listForWriter($user, $story->id),
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
            'urls' => [
                'story' => $base,
                'new' => $base . '/chapters/new',
                'move' => $base . '/chapters/move',
                'chapter' => AdminUrl::to('chapters'),
            ],
        ], $user, 'Chapters of ' . $story->title, self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @param array<string, string> $errors
     */
    private function deletePage(Request $request, User $user, Story $story, Chapter $chapter, array $errors, int $status): Response
    {
        return $this->view->render('chapter-delete', [
            'story' => $story,
            'chapter' => $chapter,
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
            'action' => AdminUrl::to('chapters/' . $chapter->id . '/delete'),
            'backUrl' => AdminUrl::to('chapters/' . $chapter->id),
        ], $user, 'Delete chapter ' . $chapter->number, self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @return array{title: string, content: string, content_format: string, notes: string}
     */
    private function postedValues(Request $request): array
    {
        return [
            'title' => $request->input('title') ?? '',
            'content' => $request->input('content') ?? '',
            'content_format' => $request->input('content_format') ?? '',
            'notes' => $request->input('notes') ?? '',
        ];
    }

    /**
     * @return array{title: string, content: string, content_format: string, notes: string}
     */
    private function valuesOf(Chapter $chapter): array
    {
        return ['title' => $chapter->title, 'content' => $chapter->content, 'content_format' => $chapter->format->value, 'notes' => $chapter->notes];
    }

    /**
     * @param array{title: string, content: string, content_format: string, notes: string} $values
     * @return array{title: string, content: string, format: string, notes: string}
     */
    private function serviceInput(array $values): array
    {
        return ['title' => $values['title'], 'content' => $values['content'], 'format' => $values['content_format'], 'notes' => $values['notes']];
    }
}
