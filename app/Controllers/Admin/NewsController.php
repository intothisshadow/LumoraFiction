<?php

/**
 * The admin screens for news posts: the list, the form, publishing, previewing and deleting.
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
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\ContentFormat;
use LumoraFiction\Models\NewsPost;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\NewsPermissionException;
use LumoraFiction\Services\NewsPresenter;
use LumoraFiction\Services\NewsService;
use LumoraFiction\Services\NewsSettings;
use LumoraFiction\Services\NewsValidationException;

/**
 * Permission is the PublishNews capability, checked here on every route and again inside the service. A post that does
 * not exist and one the person may not touch are answered the same way. A save that fails shows the form again with
 * the text exactly as it was typed.
 */
final class NewsController extends WriterController
{
    private const PATH = 'news';

    private const TOKEN = 'admin_news';

    /** @var list<string> */
    private const KEPT = ['title', 'content', 'content_format', 'pinned'];

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly NewsService $news,
        private readonly ContentEditor $editor,
        private readonly ThemeRenderer $themes,
        private readonly NewsPresenter $presenter,
        private readonly Configuration $config,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function index(array $params, Request $request): Response
    {
        $user = $this->staff($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $filters = [
            'state' => PublicationState::tryFrom($request->queryValue('state') ?? '')?->value ?? '',
            'search' => $request->queryValue('q') ?? '',
        ];

        $sort = NewsService::sortFrom($request->queryValue('sort'), $request->queryValue('dir'));
        $total = $this->news->countForStaff($user, $filters);
        $pages = max(1, (int) ceil($total / NewsService::STAFF_PAGE_SIZE));
        $page = min($pages, max(1, (int) ($request->queryValue('page') ?? 1)));

        return $this->view->render('news-list', [
            'posts' => $this->news->listForStaff($user, $filters, NewsService::STAFF_PAGE_SIZE, ($page - 1) * NewsService::STAFF_PAGE_SIZE, $sort),
            'sort' => $sort,
            'filters' => $filters,
            'states' => [PublicationState::Draft, PublicationState::Published],
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'enabled' => NewsSettings::enabled($this->config),
            'listUrl' => AdminUrl::to(self::PATH),
            'newUrl' => AdminUrl::to(self::PATH . '/new'),
            'postUrl' => AdminUrl::to(self::PATH),
            'settingsUrl' => AdminUrl::to('settings/news'),
        ], $user, 'News', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $params
     */
    public function showCreate(array $params, Request $request): Response
    {
        $user = $this->staff($request, self::PATH);

        return $user instanceof Response ? $user : $this->formPage($request, $user, null, ['title' => '', 'content' => '', 'content_format' => '', 'pinned' => false], [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(array $params, Request $request): Response
    {
        $user = $this->staff($request, self::PATH, self::PATH . '/new', self::KEPT);

        if ($user instanceof Response) {
            return $user;
        }

        $values = $this->postedValues($request);

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->formPage($request, $user, null, $values, ['form' => self::STALE_FORM], 400);
        }

        try {
            $post = $this->news->create($user, $this->serviceInput($values), $request->ip());
            $post = $this->applyAction($user, $post, $request);
        } catch (NewsValidationException $exception) {
            return $this->formPage($request, $user, null, $values, $exception->errors, 422);
        } catch (NewsPermissionException $exception) {
            return $this->missing($exception->getMessage(), self::PATH);
        }

        $this->afterSave($request, $post);

        return Response::redirect(AdminUrl::to(self::PATH . '/' . $post->id), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function showEdit(array $params, Request $request): Response
    {
        $user = $this->staff($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $post = $this->postFrom($user, $params);

        return $post === null ? $this->missing('That news post is not available.', self::PATH) : $this->formPage($request, $user, $post, $this->valuesOf($post), [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(array $params, Request $request): Response
    {
        $user = $this->staff($request, self::PATH, self::PATH . '/' . $this->idFrom($params), self::KEPT);

        if ($user instanceof Response) {
            return $user;
        }

        $post = $this->postFrom($user, $params);

        if ($post === null) {
            return $this->missing('That news post is not available.', self::PATH);
        }

        $values = $this->postedValues($request);

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->formPage($request, $user, $post, $values, ['form' => self::STALE_FORM], 400);
        }

        try {
            $post = $this->news->update($user, $post->id, $this->serviceInput($values), $request->ip());
            $post = $this->applyAction($user, $post, $request);
        } catch (NewsValidationException $exception) {
            return $this->formPage($request, $user, $post, $values, $exception->errors, 422);
        } catch (NewsPermissionException $exception) {
            return $this->missing($exception->getMessage(), self::PATH);
        }

        $this->afterSave($request, $post);

        return Response::redirect(AdminUrl::to(self::PATH . '/' . $post->id), 303);
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
        $user = $this->staff($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $post = $this->postFrom($user, $params);

        return $post === null ? $this->missing('That news post is not available.', self::PATH) : $this->deletePage($request, $user, $post, [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(array $params, Request $request): Response
    {
        $user = $this->staff($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $post = $this->postFrom($user, $params);

        if ($post === null) {
            return $this->missing('That news post is not available.', self::PATH);
        }

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->deletePage($request, $user, $post, ['form' => self::STALE_FORM], 400);
        }

        try {
            $this->news->delete($user, $post->id, $request->ip());
        } catch (NewsPermissionException $exception) {
            return $this->missing($exception->getMessage(), self::PATH);
        }

        $this->flash->add('success', 'The news post was deleted.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * The post as a reader would see it, drawn by the active theme. Works for a draft too.
     *
     * @param array<string, string> $params
     */
    public function preview(array $params, Request $request): Response
    {
        $user = $this->staff($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $post = $this->postFrom($user, $params);

        if ($post === null) {
            return $this->missing('That news post is not available.', self::PATH);
        }

        return $this->themes->render('news-single', [
            'post' => $this->presenter->single($post, AdminUrl::to(self::PATH . '/' . $post->id . '/preview')),
        ], 200, [
            'title' => $post->title,
            'noindex' => true,
            'preview' => ['label' => strtolower($post->state->label()), 'edit_url' => AdminUrl::to(self::PATH . '/' . $post->id)],
        ])->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @param array<string, string> $params
     */
    private function changeState(array $params, Request $request, bool $publish): Response
    {
        $user = $this->staff($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $post = $this->postFrom($user, $params);

        if ($post === null) {
            return $this->missing('That news post is not available.', self::PATH);
        }

        $back = Response::redirect(AdminUrl::to(self::PATH), 303);

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);

            return $back;
        }

        try {
            $publish ? $this->news->publish($user, $post->id, $request->ip()) : $this->news->unpublish($user, $post->id, $request->ip());
        } catch (NewsPermissionException $exception) {
            return $this->missing($exception->getMessage(), self::PATH);
        }

        $this->flash->add('success', $publish ? 'The news post is published.' : 'The news post is no longer visible to readers.');

        return $back;
    }

    /**
     * Carries out the button that was pressed after the text has been saved.
     *
     * @throws NewsPermissionException
     */
    private function applyAction(User $user, NewsPost $post, Request $request): NewsPost
    {
        return match ($request->input('action')) {
            'publish' => $this->news->publish($user, $post->id, $request->ip()),
            'unpublish' => $this->news->unpublish($user, $post->id, $request->ip()),
            default => $post,
        };
    }

    private function afterSave(Request $request, NewsPost $post): void
    {
        $this->flash->add('success', match ($request->input('action')) {
            'publish' => 'The news post is published.',
            'unpublish' => 'The news post was saved and is no longer visible to readers.',
            default => $post->isPublished() ? 'The news post was saved.' : 'The draft was saved.',
        });

        if ($request->input('action') === 'publish' && !NewsSettings::enabled($this->config)) {
            $this->flash->add('info', 'News is switched off in the settings, so readers cannot see this post yet.');
        }
    }

    /**
     * The signed-in person, or the response that refuses them. News is site-wide, so there is no ownership to check.
     *
     * @param list<string> $keep
     */
    private function staff(Request $request, string $currentPath, string $returnPath = '', array $keep = []): User|Response
    {
        return $this->personWith(Capability::PublishNews, $request, $currentPath, $returnPath, $keep);
    }

    /**
     * @param array<string, string> $params
     */
    private function postFrom(User $user, array $params): ?NewsPost
    {
        $id = $this->idFrom($params);

        if ($id === 0) {
            return null;
        }

        try {
            return $this->news->manageable($user, $id);
        } catch (NewsPermissionException) {
            return null;
        }
    }

    /**
     * @param array{title: string, content: string, content_format: string, pinned: bool} $values
     * @param array<string, string> $errors
     */
    private function formPage(Request $request, User $user, ?NewsPost $post, array $values, array $errors, int $status): Response
    {
        $stored = ContentFormat::tryFrom($values['content_format']) ?? $post?->format;
        $state = $post?->state ?? PublicationState::Draft;

        $actions = $state === PublicationState::Published
            ? [
                ['name' => 'action', 'value' => 'save', 'label' => 'Save changes', 'primary' => true],
                ['name' => 'action', 'value' => 'unpublish', 'label' => 'Unpublish'],
            ]
            : [
                ['name' => 'action', 'value' => 'save', 'label' => 'Save draft'],
                ['name' => 'action', 'value' => 'publish', 'label' => 'Publish', 'primary' => true],
            ];

        return $this->view->render('news-form', [
            'post' => $post,
            'values' => $values,
            'editor' => $this->editor->render($user, 'content', $values['content'], $stored, ['label' => 'Text', 'id' => 'content', 'actions' => $actions]),
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => $post === null ? AdminUrl::to(self::PATH . '/new') : AdminUrl::to(self::PATH . '/' . $post->id),
            'listUrl' => AdminUrl::to(self::PATH),
            'urls' => $post === null ? [] : [
                'preview' => AdminUrl::to(self::PATH . '/' . $post->id . '/preview'),
                'delete' => AdminUrl::to(self::PATH . '/' . $post->id . '/delete'),
            ],
            'titleMax' => NewsService::TITLE_MAX,
        ], $user, $post === null ? 'Add a news post' : 'Edit news post', self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @param array<string, string> $errors
     */
    private function deletePage(Request $request, User $user, NewsPost $post, array $errors, int $status): Response
    {
        return $this->view->render('news-delete', [
            'post' => $post,
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH . '/' . $post->id . '/delete'),
            'backUrl' => AdminUrl::to(self::PATH . '/' . $post->id),
        ], $user, 'Delete news post', self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @return array{title: string, content: string, content_format: string, pinned: bool}
     */
    private function postedValues(Request $request): array
    {
        return [
            'title' => $request->input('title') ?? '',
            'content' => $request->input('content') ?? '',
            'content_format' => $request->input('content_format') ?? '',
            'pinned' => $request->checked('pinned'),
        ];
    }

    /**
     * @return array{title: string, content: string, content_format: string, pinned: bool}
     */
    private function valuesOf(NewsPost $post): array
    {
        return ['title' => $post->title, 'content' => $post->content, 'content_format' => $post->format->value, 'pinned' => $post->pinned];
    }

    /**
     * @param array{title: string, content: string, content_format: string, pinned: bool} $values
     * @return array{title: string, content: string, format: string, pinned: bool}
     */
    private function serviceInput(array $values): array
    {
        return ['title' => $values['title'], 'content' => $values['content'], 'format' => $values['content_format'], 'pinned' => $values['pinned']];
    }
}
