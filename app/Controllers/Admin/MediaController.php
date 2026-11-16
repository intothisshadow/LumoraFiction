<?php

/**
 * The Media screen: the pictures uploaded for news posts, with how much space they take, which posts use them, and
 * where an administrator uploads and deletes them.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers\Admin;

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Admin\AdminView;
use LumoraFiction\Core\Admin\BulkResult;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Media\ImageStore;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\NewsImageService;
use LumoraFiction\Services\NewsPermissionException;
use LumoraFiction\Services\NewsService;
use LumoraFiction\Services\NewsValidationException;

/**
 * Administrators only, checked on every route. The screen reads the table of known pictures and never scans a folder, so
 * a file that is not in the table is neither shown nor deleted from here. Deleting a picture that posts still show asks
 * first, naming the posts.
 */
final class MediaController extends WriterController
{
    private const PATH = 'media';

    private const TOKEN = 'admin_media';

    private const PER_PAGE = 24;

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly NewsImageService $images,
        private readonly NewsService $news,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function index(array $params, Request $request): Response
    {
        $user = $this->admin($request);

        if ($user instanceof Response) {
            return $user;
        }

        $filters = $this->filters($request->queryValue('owner'), $request->queryValue('unused'));
        $total = $this->images->countAll($filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) ($request->queryValue('page') ?? 1)));

        return $this->view->render('media-list', [
            'rows' => $this->images->listAll($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'files' => $this->images->files(),
            'filters' => $filters,
            'uploaders' => $this->images->uploaders(),
            'totals' => $this->images->totals(),
            'found' => $total,
            'page' => $page,
            'pages' => $pages,
            'available' => NewsImageService::available(),
            'limit' => (string) round(ImageStore::limitBytes() / 1048576, 1),
            'listUrl' => AdminUrl::to(self::PATH),
            'uploadUrl' => AdminUrl::to(self::PATH . '/upload'),
            'deleteUrl' => AdminUrl::to(self::PATH . '/delete'),
            'usedUrl' => AdminUrl::to(self::PATH),
            'csrfToken' => Csrf::token(self::TOKEN),
        ], $user, 'Media', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * Stores every file chosen; one that is refused is reported by name and does not stop the others.
     *
     * @param array<string, string> $params
     */
    public function upload(array $params, Request $request): Response
    {
        $user = $this->admin($request);

        if ($user instanceof Response) {
            return $user;
        }

        $back = Response::redirect(AdminUrl::to(self::PATH), 303);

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);

            return $back;
        }

        $uploads = array_slice($request->uploads('pictures'), 0, BulkResult::LIMIT);

        if ($uploads === []) {
            $this->flash->add('error', 'Choose at least one picture to upload.');

            return $back;
        }

        $stored = 0;
        $problems = [];

        foreach ($uploads as $position => $upload) {
            try {
                $this->images->add($user, $upload, $request->ip());
                $stored++;
            } catch (NewsValidationException $exception) {
                $problems[] = ($upload->name !== '' ? $upload->name : 'Picture ' . ($position + 1)) . ': ' . implode(' ', $exception->errors);
            } catch (NewsPermissionException $exception) {
                $problems[] = $exception->getMessage();
            }
        }

        if ($stored > 0) {
            $this->flash->add('success', $stored === 1 ? '1 picture was uploaded.' : $stored . ' pictures were uploaded.');
        }

        foreach ($problems as $problem) {
            $this->flash->add('error', $problem);
        }

        return $back;
    }

    /**
     * The posts that use one picture, each linked to its editor.
     *
     * @param array<string, string> $params
     */
    public function used(array $params, Request $request): Response
    {
        $user = $this->admin($request);

        if ($user instanceof Response) {
            return $user;
        }

        $image = $this->images->find($this->idFrom($params));

        if ($image === null) {
            return $this->missing('That picture is not available.', self::PATH);
        }

        return $this->view->render('media-used', [
            'image' => $image,
            'thumb' => $this->images->files()->url($image->fileName, true),
            'posts' => $this->news->postsUsingImage($image->id),
            'listUrl' => AdminUrl::to(self::PATH),
            'editUrl' => AdminUrl::to('news'),
        ], $user, 'Where this picture is used', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * Asks before deleting one picture, from its row. A hand-made address for a picture that is gone says so.
     *
     * @param array<string, string> $params
     */
    public function showDelete(array $params, Request $request): Response
    {
        $user = $this->admin($request);

        if ($user instanceof Response) {
            return $user;
        }

        return $this->confirmPage($request, $user, [$this->idFrom($params)]);
    }

    /**
     * Deletes the ticked pictures. Without the confirmation it only shows what would happen; a picture that posts still show
     * is skipped unless the confirmation also says to take it off those posts.
     *
     * @param array<string, string> $params
     */
    public function delete(array $params, Request $request): Response
    {
        $user = $this->admin($request);

        if ($user instanceof Response) {
            return $user;
        }

        $back = Response::redirect(AdminUrl::to(self::PATH), 303);

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);

            return $back;
        }

        if (BulkResult::tooMany($request->inputList('ids'))) {
            $this->flash->add('error', 'Too many pictures were selected at once.');

            return $back;
        }

        $ids = BulkResult::ids($request->inputList('ids'));

        if ($ids === []) {
            $this->flash->add('error', 'Tick at least one picture first.');

            return $back;
        }

        if (!$request->checked('confirmed')) {
            return $this->confirmPage($request, $user, $ids);
        }

        try {
            $result = $this->images->deleteMany($user, $ids, $request->checked('remove_from_posts'), $request->ip());
        } catch (NewsPermissionException $exception) {
            $this->flash->add('error', $exception->getMessage());

            return $back;
        }

        $parts = [$result['deleted'] === 1 ? '1 picture was deleted.' : $result['deleted'] . ' pictures were deleted.'];

        if ($result['cleared'] > 0) {
            $parts[] = $result['cleared'] === 1 ? '1 post lost its picture.' : $result['cleared'] . ' posts lost their picture.';
        }

        if ($result['refused'] !== []) {
            $parts[] = count($result['refused']) . (count($result['refused']) === 1 ? ' picture is' : ' pictures are') . ' still used by news posts and was kept.';
        }

        $this->flash->add($result['refused'] === [] ? 'success' : 'warning', implode(' ', $parts));

        return $back;
    }

    /**
     * @param list<int> $ids
     */
    private function confirmPage(Request $request, User $user, array $ids): Response
    {
        $items = [];
        $used = 0;

        foreach (array_slice($ids, 0, BulkResult::LIMIT) as $id) {
            $image = $id > 0 ? $this->images->find($id) : null;

            if ($image === null) {
                continue;
            }

            $posts = $this->news->postsUsingImage($image->id);
            $used += $posts === [] ? 0 : 1;
            $items[] = ['image' => $image, 'thumb' => $this->images->files()->url($image->fileName, true), 'posts' => $posts];
        }

        if ($items === []) {
            $this->flash->add('error', 'Those pictures are no longer there.');

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        return $this->view->render('media-delete', [
            'items' => $items,
            'used' => $used,
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH . '/delete'),
            'backUrl' => AdminUrl::to(self::PATH),
            'editUrl' => AdminUrl::to('news'),
        ], $user, 'Delete pictures', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @return array{owner?: int, unused?: bool}
     */
    private function filters(?string $owner, ?string $unused): array
    {
        $filters = [];

        if ($owner !== null && preg_match('/^[1-9][0-9]{0,9}\z/', $owner) === 1) {
            $filters['owner'] = (int) $owner;
        }

        if ($unused === '1') {
            $filters['unused'] = true;
        }

        return $filters;
    }

    private function admin(Request $request): User|Response
    {
        return $this->personWith(Capability::ManageSite, $request, self::PATH);
    }
}
