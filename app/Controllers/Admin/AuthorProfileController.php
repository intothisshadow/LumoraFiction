<?php

/**
 * The writer's own profile screen: the text and the picture shown on their author page.
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
use LumoraFiction\Core\Admin\ContentEditor;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Media\AvatarImageStore;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\ContentFormat;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthorProfileException;
use LumoraFiction\Services\AuthorProfileService;
use LumoraFiction\Services\AuthService;

/**
 * Only the signed-in writer's own profile is ever read or written here: the account comes from the session, never
 * from the request, so no form can name another person's profile.
 */
final class AuthorProfileController extends AdminController
{
    private const PATH = 'profile';

    private const TOKEN_ACTION = 'admin_author_profile';

    private const STALE_FORM = 'Your session expired, so nothing was saved. Please check the form and send it again.';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly AuthorProfileService $profiles,
        private readonly ContentEditor $editor,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::WriteStories, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $saved = $this->profiles->forUser($user->id);

        return $this->page($request, $user, $saved['about'], $saved['format'], [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function saveAbout(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::WriteStories, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $about = $request->input('about') ?? '';
        $format = ContentFormat::tryFrom((string) $request->input('about_format')) ?? ContentFormat::Html;

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->page($request, $user, $about, $format, ['form' => self::STALE_FORM], 400);
        }

        try {
            $this->profiles->saveAbout($user, $about, $format, $request->ip());
        } catch (AuthorProfileException $exception) {
            return $this->page($request, $user, $about, $format, $exception->errors, 422);
        }

        $this->flash->add('success', 'Your author information was saved.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function savePicture(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::WriteStories, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $saved = $this->profiles->forUser($user->id);

        if (!$this->validToken($request, self::TOKEN_ACTION)) {
            return $this->page($request, $user, $saved['about'], $saved['format'], ['form' => self::STALE_FORM], 400);
        }

        try {
            $this->profiles->applyAvatar(
                $user,
                $request->upload('avatar'),
                $request->checked('avatar_remove'),
                AvatarImageStore::parseCrop($request->input('crop_x'), $request->input('crop_y'), $request->input('crop_w'), $request->input('crop_h')),
                $request->ip(),
            );
        } catch (AuthorProfileException $exception) {
            return $this->page($request, $user, $saved['about'], $saved['format'], $exception->errors, 422);
        }

        $this->flash->add('success', 'Your picture was saved.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, string> $errors
     */
    private function page(Request $request, User $user, string $about, ContentFormat $format, array $errors, int $status): Response
    {
        $saved = $this->profiles->forUser($user->id);
        $token = Csrf::token(self::TOKEN_ACTION);

        return $this->view->render('author-profile', [
            'editor' => $this->editor->render($user, 'about', $about, $format, [
                'label' => 'About you',
                'id' => 'about',
                'rows' => 14,
                'actions' => [['name' => 'action', 'value' => 'save', 'label' => 'Save', 'primary' => true]],
            ]),
            'errors' => $errors,
            'csrfToken' => $token,
            'aboutAction' => AdminUrl::to(self::PATH . '/about'),
            'pictureAction' => AdminUrl::to(self::PATH . '/picture'),
            'avatar' => $this->profiles->avatarUrl($saved['avatar']),
            'avatarAvailable' => AvatarImageStore::isAvailable(),
            'avatarLimit' => (string) round(AvatarImageStore::limitBytes() / 1048576, 1),
            'aboutLimit' => AuthorProfileService::ABOUT_MAX,
            'authorUrl' => PublicUrl::author($user->slug),
        ], $user, 'Author profile', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
