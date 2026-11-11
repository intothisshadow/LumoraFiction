<?php

/**
 * The admin screen and downloads for exporting stories.
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
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ExportException;
use LumoraFiction\Services\ExportService;
use LumoraFiction\Services\StoryService;

/**
 * An export is a POST with a token, never a link, so it cannot be fetched by a prefetching browser or a page that points at
 * it, and every answer is private and uncached. Who may export what is decided inside the service; this class only asks.
 */
final class ExportController extends WriterController
{
    private const PATH = 'export';

    private const TOKEN = 'admin_export';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly ExportService $exports,
        private readonly StoryService $stories,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(array $params, Request $request): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $mine = $this->stories->listForOwner($user->id);
        $mayAdminister = $user->can(Capability::ManageSite);

        return $this->view->render('export', [
            'available' => ExportService::available(),
            'stories' => $mine,
            'authors' => $mayAdminister ? $this->exports->authors() : [],
            'mayAdminister' => $mayAdminister,
            'userId' => $user->id,
            'csrfToken' => Csrf::token(self::TOKEN),
            'storyUrl' => AdminUrl::to(self::PATH . '/story'),
            'authorUrl' => AdminUrl::to(self::PATH . '/author'),
            'archiveUrl' => AdminUrl::to(self::PATH . '/archive'),
        ], $user, 'Export', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $params
     */
    public function story(array $params, Request $request): Response
    {
        return $this->download($request, fn (User $user): array => $this->exports->story($user, (int) $request->input('story_id'), $request->checked('covers'), $request->ip()), $request->input('return') === 'story' ? 'stories/' . (int) $request->input('story_id') : self::PATH);
    }

    /**
     * @param array<string, string> $params
     */
    public function author(array $params, Request $request): Response
    {
        return $this->download($request, fn (User $user): array => $this->exports->author($user, (int) $request->input('author_id'), $request->checked('covers'), $request->ip()), self::PATH);
    }

    /**
     * @param array<string, string> $params
     */
    public function archive(array $params, Request $request): Response
    {
        return $this->download($request, fn (User $user): array => $this->exports->archive($user, $request->checked('covers'), $request->ip()), self::PATH);
    }

    /**
     * @param callable(User): array{path: string, name: string, stories: int} $make
     */
    private function download(Request $request, callable $make, string $back): Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $return = Response::redirect(AdminUrl::to($back), 303);

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);

            return $return;
        }

        try {
            $file = $make($user);
        } catch (ExportException $exception) {
            $this->flash->add('error', $exception->getMessage());

            return $return;
        }

        return Response::file($file['path'], $file['name'], 'application/zip', true)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
