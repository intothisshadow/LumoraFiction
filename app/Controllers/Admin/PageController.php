<?php

/**
 * The admin screens for static pages: list, add, edit, preview, publish and delete.
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

namespace LumoraFiction\Controllers\Admin;

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Admin\AdminView;
use LumoraFiction\Core\Admin\BulkBar;
use LumoraFiction\Core\Admin\ContentEditor;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\ContentFormat;
use LumoraFiction\Models\Page;
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\PagePermissionException;
use LumoraFiction\Services\PagePresenter;
use LumoraFiction\Services\PageService;
use LumoraFiction\Services\PageValidationException;

/**
 * Permission is the ManageSite capability, checked here on every route and again inside the service. A page that does
 * not exist and one the person may not touch are answered the same way. A save that fails shows the form again with
 * the text exactly as it was typed.
 */
final class PageController extends WriterController
{
    private const PATH = 'pages';

    private const TOKEN = 'admin_pages';

    /** @var list<string> */
    private const KEPT = ['title', 'slug', 'content', 'content_format', 'in_menu', 'in_footer', 'sort_order', 'parent_id', 'description', 'noindex'];

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly PageService $pages,
        private readonly ContentEditor $editor,
        private readonly ThemeRenderer $themes,
        private readonly PagePresenter $presenter,
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

        $sort = PageService::sortFrom($request->queryValue('sort'), $request->queryValue('dir'));
        $total = $this->pages->countForStaff($user, $filters);
        $pageCount = max(1, (int) ceil($total / PageService::STAFF_PAGE_SIZE));
        $page = min($pageCount, max(1, (int) ($request->queryValue('page') ?? 1)));

        $rows = $this->pages->listForStaff($user, $filters, PageService::STAFF_PAGE_SIZE, ($page - 1) * PageService::STAFF_PAGE_SIZE, $sort);

        return $this->view->render('pages-list', [
            'pages' => $rows,
            'parents' => $this->pages->parentsOf($rows),
            'sort' => $sort,
            'filters' => $filters,
            'states' => [PublicationState::Draft, PublicationState::Published],
            'total' => $total,
            'page' => $page,
            'pageCount' => $pageCount,
            'hasAny' => $this->pages->countAll() > 0,
            'starters' => $this->pages->availableStarters(),
            'starterToken' => Csrf::token(self::TOKEN),
            'starterUrl' => AdminUrl::to(self::PATH . '/starter'),
            'listUrl' => AdminUrl::to(self::PATH),
            'newUrl' => AdminUrl::to(self::PATH . '/new'),
            'pageUrl' => AdminUrl::to(self::PATH),
            'bulkUrl' => AdminUrl::to(self::PATH . '/bulk'),
        ], $user, 'Pages', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * Publishes, unpublishes, places in the menu or footer, takes out of both, or deletes the ticked pages. Deleting asks first.
     *
     * @param array<string, string> $params
     */
    public function bulk(array $params, Request $request): Response
    {
        $user = $this->staff($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $kept = BulkBar::kept($request, PageService::sortFrom($request->input('sort'), $request->input('dir')), [
            'state' => PublicationState::tryFrom($request->input('state') ?? '')?->value ?? '',
            'q' => mb_substr($request->input('q') ?? '', 0, 100),
        ]);
        $back = BulkBar::listUrl(AdminUrl::to(self::PATH), $kept);
        $input = $this->bulkInput($request, self::TOKEN, PageService::BULK_ACTIONS, 'pages', $back);

        if ($input instanceof Response) {
            return $input;
        }

        if ($input['action'] === 'delete' && !$request->checked('confirmed')) {
            $items = [];

            foreach ($input['ids'] as $id) {
                $page = $this->pages->find($id);

                if ($page !== null) {
                    $items[] = ['id' => $page->id, 'label' => $page->title];
                }
            }

            return $this->askBeforeBulk($request, $user, self::TOKEN, 'Delete ' . (count($items) === 1 ? 'this page' : 'these ' . count($items) . ' pages') . '?', 'The pages are removed for good, and they leave the menu and footer. Visitors who open their addresses will no longer find them. This cannot be undone. To only hide them, unpublish them instead.', 'Delete', $items, ['bulk_action' => 'delete'] + $kept, AdminUrl::to(self::PATH . '/bulk'), $back, 'Back to the pages', self::PATH);
        }

        try {
            $result = $this->pages->bulk($user, $input['action'], $input['ids'], $request->ip());
        } catch (PagePermissionException $exception) {
            return $this->missing($exception->getMessage(), self::PATH);
        } catch (PageValidationException $exception) {
            $this->flash->add('error', implode(' ', $exception->errors));

            return Response::redirect($back, 303);
        }

        $this->flash->add($result->flashType(), $result->summary(['publish' => 'published', 'unpublish' => 'made drafts again', 'menu' => 'added to the menu', 'footer' => 'added to the footer', 'remove' => 'taken out of the menu and footer', 'delete' => 'deleted'][$input['action']], 'page'));

        return Response::redirect($back, 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function showCreate(array $params, Request $request): Response
    {
        $user = $this->staff($request, self::PATH);

        return $user instanceof Response ? $user : $this->formPage($request, $user, null, $this->emptyValues(), [], 200);
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
            $page = $this->pages->create($user, $this->serviceInput($values), $request->ip());
            $page = $this->applyAction($user, $page, $request);
        } catch (PageValidationException $exception) {
            return $this->formPage($request, $user, null, $values, $exception->errors, 422);
        } catch (PagePermissionException $exception) {
            return $this->missing($exception->getMessage(), self::PATH);
        }

        $this->afterSave($request, $page);

        return Response::redirect(AdminUrl::to(self::PATH . '/' . $page->id), 303);
    }

    /**
     * Adds one starter draft from the list offered on an empty Pages screen.
     *
     * @param array<string, string> $params
     */
    public function starter(array $params, Request $request): Response
    {
        $user = $this->staff($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->missing(self::STALE_FORM, self::PATH);
        }

        try {
            $page = $this->pages->createStarter($user, $request->input('starter') ?? '', $request->ip());
        } catch (PageValidationException $exception) {
            return $this->missing(array_values($exception->errors)[0] ?? 'That starter page could not be added.', self::PATH);
        } catch (PagePermissionException $exception) {
            return $this->missing($exception->getMessage(), self::PATH);
        }

        $this->flash->add('success', 'The starter draft was added. Replace its text before you publish it.');

        return Response::redirect(AdminUrl::to(self::PATH . '/' . $page->id), 303);
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

        $page = $this->pageFrom($user, $params);

        return $page === null ? $this->missing('That page is not available.', self::PATH) : $this->formPage($request, $user, $page, $this->valuesOf($page), [], 200);
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

        $page = $this->pageFrom($user, $params);

        if ($page === null) {
            return $this->missing('That page is not available.', self::PATH);
        }

        $values = $this->postedValues($request);

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->formPage($request, $user, $page, $values, ['form' => self::STALE_FORM], 400);
        }

        try {
            $page = $this->pages->update($user, $page->id, $this->serviceInput($values), $request->ip());
            $page = $this->applyAction($user, $page, $request);
        } catch (PageValidationException $exception) {
            return $this->formPage($request, $user, $page, $values, $exception->errors, 422);
        } catch (PagePermissionException $exception) {
            return $this->missing($exception->getMessage(), self::PATH);
        }

        $this->afterSave($request, $page);

        return Response::redirect(AdminUrl::to(self::PATH . '/' . $page->id), 303);
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

        $page = $this->pageFrom($user, $params);

        return $page === null ? $this->missing('That page is not available.', self::PATH) : $this->deletePage($request, $user, $page, [], 200);
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

        $page = $this->pageFrom($user, $params);

        if ($page === null) {
            return $this->missing('That page is not available.', self::PATH);
        }

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->deletePage($request, $user, $page, ['form' => self::STALE_FORM], 400);
        }

        try {
            $this->pages->delete($user, $page->id, $request->ip());
        } catch (PagePermissionException $exception) {
            return $this->missing($exception->getMessage(), self::PATH);
        }

        $this->flash->add('success', 'The page was deleted.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * The page as a reader would see it, drawn by the active theme. Works for a draft too, and for nobody but an administrator.
     *
     * @param array<string, string> $params
     */
    public function preview(array $params, Request $request): Response
    {
        $user = $this->staff($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $page = $this->pageFrom($user, $params);

        if ($page === null) {
            return $this->missing('That page is not available.', self::PATH);
        }

        return $this->themes->render('page', ['page' => $this->presenter->single($page)], 200, [
            'title' => $page->title,
            'noindex' => true,
            'preview' => ['label' => strtolower($page->state->label()), 'edit_url' => AdminUrl::to(self::PATH . '/' . $page->id)],
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

        $page = $this->pageFrom($user, $params);

        if ($page === null) {
            return $this->missing('That page is not available.', self::PATH);
        }

        $back = Response::redirect(AdminUrl::to(self::PATH), 303);

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);

            return $back;
        }

        try {
            $publish ? $this->pages->publish($user, $page->id, $request->ip()) : $this->pages->unpublish($user, $page->id, $request->ip());
        } catch (PagePermissionException $exception) {
            return $this->missing($exception->getMessage(), self::PATH);
        }

        $this->flash->add('success', $publish ? 'The page is published.' : 'The page is no longer visible to readers.');

        return $back;
    }

    /**
     * Carries out the button that was pressed after the text has been saved.
     *
     * @throws PagePermissionException
     */
    private function applyAction(User $user, Page $page, Request $request): Page
    {
        return match ($request->input('action')) {
            'publish' => $this->pages->publish($user, $page->id, $request->ip()),
            'unpublish' => $this->pages->unpublish($user, $page->id, $request->ip()),
            default => $page,
        };
    }

    private function afterSave(Request $request, Page $page): void
    {
        $this->flash->add('success', match ($request->input('action')) {
            'publish' => 'The page is published.',
            'unpublish' => 'The page was saved and is no longer visible to readers.',
            default => $page->isPublished() ? 'The page was saved.' : 'The draft was saved.',
        });
    }

    /**
     * The signed-in person, or the response that refuses them. Pages are site-wide, so there is no ownership to check.
     *
     * @param list<string> $keep
     */
    private function staff(Request $request, string $currentPath, string $returnPath = '', array $keep = []): User|Response
    {
        return $this->personWith(Capability::ManageSite, $request, $currentPath, $returnPath, $keep);
    }

    /**
     * @param array<string, string> $params
     */
    private function pageFrom(User $user, array $params): ?Page
    {
        $id = $this->idFrom($params);

        if ($id === 0) {
            return null;
        }

        try {
            return $this->pages->manageable($user, $id);
        } catch (PagePermissionException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function formPage(Request $request, User $user, ?Page $page, array $values, array $errors, int $status): Response
    {
        $stored = ContentFormat::tryFrom((string) $values['content_format']) ?? $page?->format;
        $state = $page?->state ?? PublicationState::Draft;

        $actions = $state === PublicationState::Published
            ? [
                ['name' => 'action', 'value' => 'save', 'label' => 'Save changes', 'primary' => true],
                ['name' => 'action', 'value' => 'unpublish', 'label' => 'Unpublish'],
            ]
            : [
                ['name' => 'action', 'value' => 'save', 'label' => 'Save draft'],
                ['name' => 'action', 'value' => 'publish', 'label' => 'Publish', 'primary' => true],
            ];

        return $this->view->render('page-form', [
            'page' => $page,
            'values' => $values,
            'parentChoices' => $this->pages->parentChoices($page),
            'childCount' => $page === null ? 0 : $this->pages->childCount($page->id),
            'editor' => $this->editor->render($user, 'content', (string) $values['content'], $stored, ['label' => 'Text', 'id' => 'content', 'actions' => $actions]),
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => $page === null ? AdminUrl::to(self::PATH . '/new') : AdminUrl::to(self::PATH . '/' . $page->id),
            'listUrl' => AdminUrl::to(self::PATH),
            'urls' => $page === null ? [] : [
                'preview' => AdminUrl::to(self::PATH . '/' . $page->id . '/preview'),
                'delete' => AdminUrl::to(self::PATH . '/' . $page->id . '/delete'),
                'public' => $page->isPublished() ? PublicUrl::page($page->slug) : '',
            ],
            'limits' => ['title' => PageService::TITLE_MAX, 'slug' => PageService::SLUG_MAX, 'description' => PageService::DESCRIPTION_MAX],
        ], $user, $page === null ? 'Add a page' : 'Edit page', self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @param array<string, string> $errors
     */
    private function deletePage(Request $request, User $user, Page $page, array $errors, int $status): Response
    {
        return $this->view->render('page-delete', [
            'page' => $page,
            'childCount' => $this->pages->childCount($page->id),
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH . '/' . $page->id . '/delete'),
            'backUrl' => AdminUrl::to(self::PATH . '/' . $page->id),
        ], $user, 'Delete page', self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyValues(): array
    {
        return ['title' => '', 'slug' => '', 'content' => '', 'content_format' => '', 'in_menu' => false, 'in_footer' => false, 'sort_order' => '0', 'parent_id' => '', 'description' => '', 'noindex' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private function postedValues(Request $request): array
    {
        return [
            'title' => $request->input('title') ?? '',
            'slug' => $request->input('slug') ?? '',
            'content' => $request->input('content') ?? '',
            'content_format' => $request->input('content_format') ?? '',
            'in_menu' => $request->checked('in_menu'),
            'in_footer' => $request->checked('in_footer'),
            'sort_order' => $request->input('sort_order') ?? '0',
            'parent_id' => $request->input('parent_id') ?? '',
            'description' => $request->input('description') ?? '',
            'noindex' => $request->checked('noindex'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function valuesOf(Page $page): array
    {
        return [
            'title' => $page->title,
            'slug' => $page->slug,
            'content' => $page->content,
            'content_format' => $page->format->value,
            'in_menu' => $page->inMenu,
            'in_footer' => $page->inFooter,
            'sort_order' => (string) $page->sortOrder,
            'parent_id' => $page->parentId === null ? '' : (string) $page->parentId,
            'description' => $page->description,
            'noindex' => $page->noindex,
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function serviceInput(array $values): array
    {
        return [
            'title' => $values['title'],
            'slug' => $values['slug'],
            'content' => $values['content'],
            'format' => $values['content_format'],
            'in_menu' => $values['in_menu'],
            'in_footer' => $values['in_footer'],
            'sort_order' => $values['sort_order'],
            'parent_id' => $values['parent_id'],
            'description' => $values['description'],
            'noindex' => $values['noindex'],
        ];
    }
}
