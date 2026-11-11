<?php

/**
 * Classification screens: the lists of fandoms, characters, tags and the rest, and which kinds are required.
 *
 * @package LumoraFiction
 * @subpackage Admin
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
use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ClassificationLabelSettings;
use LumoraFiction\Services\TaxonomyException;
use LumoraFiction\Services\TaxonomyService;

/**
 * Every screen is for administrators. A form that fails is shown again with what was typed and the
 * reason; a stale form token never changes anything.
 */
final class TaxonomyController extends AdminController
{
    private const PATH = 'taxonomy';

    private const PER_PAGE = 50;

    private const TOKEN_ACTION = 'admin_taxonomy';

    private const STALE_FORM = 'Your session expired, so nothing was saved. Please check the form and send it again.';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly TaxonomyService $taxonomy,
        private readonly ?ClassificationLabelSettings $labelSettings = null,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function saveLabels(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response || $this->labelSettings === null) {
            return $user instanceof Response ? $user : $this->missing('Names of the lists cannot be changed here.');
        }

        $enabled = $this->taxonomy->enabledTypes();
        $posted = [];

        foreach ($enabled as $type) {
            $posted[$type->value] = [
                'singular' => (string) $request->input('label_' . $type->value . '_singular'),
                'plural' => (string) $request->input('label_' . $type->value . '_plural'),
            ];
        }

        $result = $this->labelSettings->validate($posted, $enabled);

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->overviewPage($request, $user, null, ['form' => self::STALE_FORM], 400, $posted);
        }

        if ($result['errors'] !== []) {
            return $this->overviewPage($request, $user, null, $result['errors'], 422, $posted);
        }

        $this->flash->add('success', $this->labelSettings->save($result['values'], $user, $request->ip()) ? 'Names saved.' : 'Nothing needed changing.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function overview(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        return $user instanceof Response ? $user : $this->overviewPage($request, $user, null, [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function saveRequired(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $chosen = [];

        foreach ($this->taxonomy->enabledTypes() as $type) {
            if ($request->checked('required_' . $type->value)) {
                $chosen[] = $type->value;
            }
        }

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->overviewPage($request, $user, $chosen, ['form' => self::STALE_FORM], 400);
        }

        try {
            $this->taxonomy->saveRequiredTypes($user, $chosen, $request->ip());
        } catch (TaxonomyException $exception) {
            return $this->overviewPage($request, $user, $chosen, ['form' => $exception->getMessage()], 422);
        }

        $this->flash->add('success', 'Required fields saved.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function list(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $type = $this->enabledType($params['type'] ?? '');

        if ($type === null) {
            return $this->missing('That kind of classification is not available.');
        }

        $listUrl = AdminUrl::to(self::PATH . '/' . $type->value);

        // The older address of the unapproved filter, still linked from bookmarks, becomes the current one.
        if ($request->queryValue('unreviewed') === '1' && $request->queryValue('status') === null) {
            return Response::redirect($listUrl . $this->listQuery($request->queryValue('q') ?? '', 'unapproved', (int) ($request->queryValue('page') ?? 1)), 302);
        }

        $filters = ['search' => $request->queryValue('q') ?? '', 'status' => $this->status($request->queryValue('status'))];
        $total = $this->taxonomy->count($type, $filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) ($request->queryValue('page') ?? 1)));
        $terms = $this->taxonomy->list($type, $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return $this->view->render('taxonomy-list', [
            'labels' => $this->taxonomy->labels(),
            'type' => $type,
            'curated' => !$this->taxonomy->writersMayPropose($type),
            'terms' => $terms,
            'parents' => $this->taxonomy->namesFor(array_map(static fn (Term $term): int => $term->parentId, $terms)),
            'filters' => $filters,
            'statusCounts' => $this->taxonomy->statusCounts($type, $filters['search']),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
            'bulkUrl' => $listUrl . '/bulk',
            'bulkLimit' => TaxonomyService::BULK_LIMIT,
            'listUrl' => $listUrl,
            'newUrl' => AdminUrl::to(self::PATH . '/' . $type->value . '/new'),
            'overviewUrl' => AdminUrl::to(self::PATH),
            'termUrl' => AdminUrl::to(self::PATH . '/term'),
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
        ], $user, $this->taxonomy->labels()->plural($type), self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * Approves, unapproves or deletes the ticked terms, or every term the current filter matches. Deleting, and any
     * action on "everything that matches", asks for confirmation with the exact count first.
     *
     * @param array<string, string> $params
     */
    public function bulk(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $type = $this->enabledType($params['type'] ?? '');

        if ($type === null) {
            return $this->missing('That kind of classification is not available.');
        }

        $search = trim($request->input('q') ?? '');
        $status = $this->status($request->input('status'));
        $page = max(1, (int) ($request->input('page') ?? 1));
        $back = Response::redirect(AdminUrl::to(self::PATH . '/' . $type->value) . $this->listQuery($search, $status, $page), 303);

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            $this->flash->add('error', self::STALE_FORM);

            return $back;
        }

        $action = $request->input('bulk_action') ?? '';
        $everything = $request->checked('all_matching');
        $confirmed = $request->checked('confirmed');

        if (!in_array($action, ['approve', 'unapprove', 'delete'], true)) {
            $this->flash->add('error', 'Choose what to do with the selected terms.');

            return $back;
        }

        $ids = [];

        if ($everything) {
            // Read now, from the filter as it stands, and one more than the cap so "too many" can be told from "exactly the cap".
            $ids = $this->taxonomy->idsMatching($type, ['search' => $search, 'status' => $status], TaxonomyService::BULK_LIMIT + 1);

            if (count($ids) > TaxonomyService::BULK_LIMIT) {
                $this->flash->add('error', 'More than ' . TaxonomyService::BULK_LIMIT . ' terms match. Narrow the list with the search or the status filter, then try again.');

                return $back;
            }

            if ($confirmed && count($ids) !== (int) ($request->input('expected') ?? -1)) {
                $this->flash->add('error', 'The list changed since you asked, so nothing was changed. Please check it and try again.');

                return $back;
            }
        } else {
            foreach ($request->inputList('ids') as $value) {
                if (preg_match('/^[1-9][0-9]{0,9}\z/', $value) === 1) {
                    $ids[] = (int) $value;
                }
            }

            $ids = array_values(array_unique($ids));
        }

        if ($ids === []) {
            $this->flash->add('error', $everything ? 'Nothing matches the current list.' : 'Tick at least one term first.');

            return $back;
        }

        if (($action === 'delete' || $everything) && !$confirmed) {
            return $this->bulkConfirmPage($request, $user, $type, $action, $ids, $everything, $search, $status, $page);
        }

        try {
            $result = $this->taxonomy->bulk($user, $type, $action, $ids, $request->ip());
        } catch (TaxonomyException $exception) {
            $this->flash->add('error', $exception->getMessage());

            return $back;
        }

        $this->flash->add($result['changed'] > 0 || $result['skipped'] === [] ? 'success' : 'warning', $this->bulkMessage($action, $result));

        return $back;
    }

    /**
     * @param array<string, string> $params
     */
    public function showCreate(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $type = $this->enabledType($params['type'] ?? '');

        return $type === null
            ? $this->missing('That kind of classification is not available.')
            : $this->createPage($request, $user, $type, ['name' => '', 'fandom' => ''], [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $type = $this->enabledType($params['type'] ?? '');

        if ($type === null) {
            return $this->missing('That kind of classification is not available.');
        }

        $values = ['name' => $request->input('name') ?? '', 'fandom' => $request->input('fandom') ?? ''];

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->createPage($request, $user, $type, $values, ['form' => self::STALE_FORM], 400);
        }

        try {
            $parentId = $this->fandomId($type, $values['fandom']);
            $term = $this->taxonomy->create($user, $type, $values['name'], $parentId, $request->ip());
        } catch (TaxonomyException $exception) {
            return $this->createPage($request, $user, $type, $values, [$this->errorField($exception, $type) => $exception->getMessage()], 422);
        }

        $this->flash->add('success', '"' . $term->name . '" was added.');

        return Response::redirect(AdminUrl::to(self::PATH . '/term/' . $term->id), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function showEdit(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $term = $this->term($params);

        return $term === null ? $this->missing('That term no longer exists.') : $this->editPage($request, $user, $term, ['name' => $term->name, 'alias' => ''], [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(array $params, Request $request): Response
    {
        return $this->act($params, $request, 'name', function (User $user, Term $term, Request $request): string {
            $this->taxonomy->rename($user, $term->id, $request->input('name') ?? '', $request->ip());
            $this->taxonomy->setReviewed($user, $term->id, $request->checked('reviewed'), $request->ip());

            // Only the short lists have these fields, so a form for an open list must not blank them.
            if (!$this->taxonomy->writersMayPropose($term->type)) {
                $this->taxonomy->setDescription($user, $term->id, $request->input('description') ?? '', $request->ip());
                $this->taxonomy->setHidden($user, $term->id, $request->checked('hidden'), $request->ip());
            }

            return 'Saved.';
        });
    }

    /**
     * Moves a value one place up or down in a short list.
     *
     * @param array<string, string> $params
     */
    public function move(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $term = $this->term($params);

        if ($term === null) {
            return $this->missing('That term no longer exists.');
        }

        $back = Response::redirect(AdminUrl::to(self::PATH . '/' . $term->type->value), 303);

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            $this->flash->add('error', self::STALE_FORM);

            return $back;
        }

        try {
            $this->taxonomy->move($user, $term->id, $request->input('direction') ?? '', $request->ip());
        } catch (TaxonomyException $exception) {
            $this->flash->add('error', $exception->getMessage());
        }

        return $back;
    }

    /**
     * @param array<string, string> $params
     */
    public function addAlias(array $params, Request $request): Response
    {
        return $this->act($params, $request, 'alias', function (User $user, Term $term, Request $request): string {
            $this->taxonomy->addAlias($user, $term->id, $request->input('alias') ?? '', $request->ip());

            return 'Alias added.';
        });
    }

    /**
     * @param array<string, string> $params
     */
    public function removeAlias(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            $this->flash->add('error', self::STALE_FORM);

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        try {
            $term = $this->taxonomy->removeAlias($user, (int) ($params['id'] ?? 0), $request->ip());
        } catch (TaxonomyException $exception) {
            return $this->missing($exception->getMessage());
        }

        $this->flash->add('success', 'Alias removed.');

        return Response::redirect(AdminUrl::to($term === null ? self::PATH : self::PATH . '/term/' . $term->id), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function showMerge(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $term = $this->term($params);

        return $term === null ? $this->missing('That term no longer exists.') : $this->mergePage($request, $user, $term, '', [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function merge(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $term = $this->term($params);

        if ($term === null) {
            return $this->missing('That term no longer exists.');
        }

        $targetName = $request->input('target') ?? '';

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->mergePage($request, $user, $term, $targetName, ['form' => self::STALE_FORM], 400);
        }

        try {
            $target = $this->taxonomy->resolve($term->type, $this->taxonomy->cleanName($targetName), $term->parentId);

            if ($target === null) {
                throw new TaxonomyException('There is no ' . $this->taxonomy->labels()->lower($term->type) . ' with that name' . ($term->type->belongsToFandom() ? ' in the same fandom' : '') . '.');
            }

            $survivor = $this->taxonomy->merge($user, $term->id, $target->id, $request->ip());
        } catch (TaxonomyException $exception) {
            return $this->mergePage($request, $user, $term, $targetName, ['target' => $exception->getMessage()], 422);
        }

        $this->flash->add('success', '"' . $term->name . '" was merged into "' . $survivor->name . '".');

        return Response::redirect(AdminUrl::to(self::PATH . '/term/' . $survivor->id), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function showDelete(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $term = $this->term($params);

        return $term === null ? $this->missing('That term no longer exists.') : $this->deletePage($request, $user, $term, [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $term = $this->term($params);

        if ($term === null) {
            return $this->missing('That term no longer exists.');
        }

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->deletePage($request, $user, $term, ['form' => self::STALE_FORM], 400);
        }

        try {
            $this->taxonomy->delete($user, $term->id, $request->ip());
        } catch (TaxonomyException $exception) {
            return $this->deletePage($request, $user, $term, ['form' => $exception->getMessage()], 422);
        }

        $this->flash->add('success', '"' . $term->name . '" was deleted.');

        return Response::redirect(AdminUrl::to(self::PATH . '/' . $term->type->value), 303);
    }

    /**
     * Runs one change from the edit screen: checks the token, applies it, and shows the screen again on failure.
     *
     * @param array<string, string> $params
     * @param callable(User, Term, Request): string $change returns the message to show on success
     */
    private function act(array $params, Request $request, string $errorField, callable $change): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $term = $this->term($params);

        if ($term === null) {
            return $this->missing('That term no longer exists.');
        }

        $values = ['name' => $request->input('name') ?? $term->name, 'alias' => $request->input('alias') ?? '', 'description' => $request->input('description') ?? $term->description, 'hidden' => $request->checked('hidden')];

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->editPage($request, $user, $term, $values, ['form' => self::STALE_FORM], 400);
        }

        try {
            $message = $change($user, $term, $request);
        } catch (TaxonomyException $exception) {
            return $this->editPage($request, $user, $term, $values, [$errorField => $exception->getMessage()], 422);
        }

        $this->flash->add('success', $message);

        return Response::redirect(AdminUrl::to(self::PATH . '/term/' . $term->id), 303);
    }

    /**
     * @param list<string>|null $required kinds ticked on a form that failed; null shows what is saved
     * @param array<string, string> $errors
     */
    private function overviewPage(Request $request, User $user, ?array $required, array $errors, int $status, ?array $labelValues = null): Response
    {
        $counts = $this->taxonomy->overview();
        $enabled = $this->taxonomy->enabledTypes();
        $required ??= array_map(static fn (TermType $type): string => $type->value, $this->taxonomy->requiredTypes());

        return $this->view->render('taxonomy-overview', [
            'labels' => $this->taxonomy->labels(),
            'types' => $enabled,
            'hiddenTypes' => array_values(array_filter(TermType::cases(), static fn (TermType $type): bool => !in_array($type, $enabled, true))),
            'counts' => $counts,
            'required' => $required,
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
            'action' => AdminUrl::to(self::PATH . '/required'),
            'labelsAction' => AdminUrl::to(self::PATH . '/labels'),
            'labelValues' => $labelValues ?? $this->taxonomy->labels()->custom(),
            'baseUrl' => AdminUrl::to(self::PATH),
        ], $user, 'Classification', self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @param array{name: string, fandom: string} $values
     * @param array<string, string> $errors
     */
    private function createPage(Request $request, User $user, TermType $type, array $values, array $errors, int $status): Response
    {
        return $this->view->render('taxonomy-form', [
            'labels' => $this->taxonomy->labels(),
            'mode' => 'create',
            'type' => $type,
            'curated' => !$this->taxonomy->writersMayPropose($type),
            'term' => null,
            'aliases' => [],
            'parentName' => '',
            'values' => $values + ['alias' => ''],
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
            'action' => AdminUrl::to(self::PATH . '/' . $type->value . '/new'),
            'listUrl' => AdminUrl::to(self::PATH . '/' . $type->value),
            'urls' => [],
        ], $user, 'Add ' . $this->taxonomy->labels()->lower($type), self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @param array{name: string, alias: string} $values
     * @param array<string, string> $errors
     */
    private function editPage(Request $request, User $user, Term $term, array $values, array $errors, int $status): Response
    {
        $base = AdminUrl::to(self::PATH . '/term/' . $term->id);

        return $this->view->render('taxonomy-form', [
            'labels' => $this->taxonomy->labels(),
            'mode' => 'edit',
            'type' => $term->type,
            'curated' => !$this->taxonomy->writersMayPropose($term->type),
            'term' => $term,
            'aliases' => $this->taxonomy->aliasesFor($term->id),
            'parentName' => $this->taxonomy->namesFor([$term->parentId])[$term->parentId] ?? '',
            'values' => $values + ['fandom' => ''],
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
            'action' => $base,
            'listUrl' => AdminUrl::to(self::PATH . '/' . $term->type->value),
            'urls' => [
                'alias' => $base . '/aliases',
                'removeAlias' => AdminUrl::to(self::PATH . '/alias'),
                'merge' => $base . '/merge',
                'delete' => $base . '/delete',
            ],
        ], $user, $term->name, self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @param array<string, string> $errors
     */
    private function mergePage(Request $request, User $user, Term $term, string $target, array $errors, int $status): Response
    {
        return $this->view->render('taxonomy-merge', [
            'labels' => $this->taxonomy->labels(),
            'term' => $term,
            'target' => $target,
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
            'action' => AdminUrl::to(self::PATH . '/term/' . $term->id . '/merge'),
            'backUrl' => AdminUrl::to(self::PATH . '/term/' . $term->id),
        ], $user, 'Merge ' . $term->name, self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @param array<string, string> $errors
     */
    private function deletePage(Request $request, User $user, Term $term, array $errors, int $status): Response
    {
        return $this->view->render('taxonomy-delete', [
            'labels' => $this->taxonomy->labels(),
            'term' => $term,
            'errors' => $errors,
            'errorSummary' => $this->errorSummary($errors),
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
            'action' => AdminUrl::to(self::PATH . '/term/' . $term->id . '/delete'),
            'backUrl' => AdminUrl::to(self::PATH . '/term/' . $term->id),
        ], $user, 'Delete ' . $term->name, self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @param list<int> $ids
     */
    private function bulkConfirmPage(Request $request, User $user, TermType $type, string $action, array $ids, bool $everything, string $search, string $status, int $page): Response
    {
        $shown = [];

        // Names are listed so the person sees what they are about to remove; a long run is cut off, the count is not.
        foreach (array_slice($ids, 0, 50) as $id) {
            $term = $this->taxonomy->find($id);

            if ($term !== null && $term->type === $type) {
                $shown[] = $term->name;
            }
        }

        return $this->view->render('taxonomy-bulk-confirm', [
            'labels' => $this->taxonomy->labels(),
            'type' => $type,
            'action' => $action,
            'count' => count($ids),
            'names' => $shown,
            'ids' => $everything ? [] : $ids,
            'everything' => $everything,
            'search' => $search,
            'status' => $status,
            'page' => $page,
            'csrfToken' => Csrf::token(self::TOKEN_ACTION),
            'formAction' => AdminUrl::to(self::PATH . '/' . $type->value . '/bulk'),
            'backUrl' => AdminUrl::to(self::PATH . '/' . $type->value) . $this->listQuery($search, $status, $page),
        ], $user, 'Confirm: ' . $this->taxonomy->labels()->lowerPlural($type), self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array{changed: int, unchanged: int, skipped: array<string, int>} $result
     */
    private function bulkMessage(string $action, array $result): string
    {
        $noun = static fn (int $count): string => $count . ($count === 1 ? ' term' : ' terms');
        $verb = ['approve' => 'approved', 'unapprove' => 'marked as unapproved', 'delete' => 'deleted'][$action];
        $parts = [$result['changed'] > 0 ? $noun($result['changed']) . ($result['changed'] === 1 ? ' was ' : ' were ') . $verb . '.' : 'No term was ' . $verb . '.'];

        if ($result['unchanged'] > 0) {
            $parts[] = $noun($result['unchanged']) . ($result['unchanged'] === 1 ? ' was' : ' were') . ' already ' . ($action === 'approve' ? 'approved' : 'unapproved') . '.';
        }

        $skipped = [];

        foreach ($result['skipped'] as $reason => $count) {
            $skipped[] = $noun($count) . ' skipped: ' . $reason;
        }

        if ($skipped !== []) {
            $parts[] = implode('; ', $skipped) . '.';
        }

        return implode(' ', $parts);
    }

    /** Only the two real filters are honoured; anything else shows the whole list. */
    private function status(?string $value): string
    {
        return in_array($value, ['approved', 'unapproved'], true) ? (string) $value : '';
    }

    private function listQuery(string $search, string $status, int $page): string
    {
        $parts = array_filter(['q' => $search, 'status' => $status, 'page' => $page > 1 ? (string) $page : ''], static fn (string $value): bool => $value !== '');

        return $parts === [] ? '' : '?' . http_build_query($parts);
    }

    /**
     * @param array<string, string> $errors
     */
    private function errorSummary(array $errors): string
    {
        return $this->view->fragment('error-summary', ['errors' => $errors]);
    }

    /**
     * A kind that exists but is switched off by the archive mode is treated as missing, so a hidden field has no screens.
     */
    private function enabledType(string $name): ?TermType
    {
        $type = TermType::tryFrom($name);

        return $type !== null && $this->taxonomy->isEnabled($type) ? $type : null;
    }

    /**
     * @param array<string, string> $params
     */
    private function term(array $params): ?Term
    {
        $id = $params['id'] ?? '';

        return preg_match('/^[1-9][0-9]{0,9}\z/', $id) === 1 ? $this->taxonomy->find((int) $id) : null;
    }

    /**
     * The fandom named in the form, or 0 for none. Only characters and relationships have one.
     *
     * @throws TaxonomyException
     */
    private function fandomId(TermType $type, string $name): int
    {
        if (!$type->belongsToFandom() || trim($name) === '') {
            return 0;
        }

        $fandom = $this->taxonomy->resolve(TermType::Fandom, $this->taxonomy->cleanName($name));

        if ($fandom === null) {
            throw new TaxonomyException('Choose one of the fandoms on the list.');
        }

        return $fandom->id;
    }

    private function errorField(TaxonomyException $exception, TermType $type): string
    {
        return $type->belongsToFandom() && str_contains($exception->getMessage(), 'fandom') ? 'fandom' : 'name';
    }

    private function missing(string $message): Response
    {
        $this->flash->add('error', $message);

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }
}
