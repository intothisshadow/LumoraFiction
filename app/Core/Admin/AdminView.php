<?php

/**
 * Renders admin screens inside the shared layout.
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

namespace LumoraFiction\Core\Admin;

use InvalidArgumentException;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\User;

/**
 * Views are plain PHP files that only print what they are given. Every response is marked private
 * and uncacheable: an admin page can hold a form token or details about the signed-in person.
 */
final class AdminView
{
    public function __construct(
        private readonly string $viewsPath,
        private readonly AdminMenu $menu,
        private readonly string $siteName,
        private readonly string $version,
    ) {
    }

    /**
     * A screen inside the full layout, for a signed-in user.
     *
     * @param array<string, mixed> $data
     * @param list<array{type: string, message: string}> $flash
     */
    public function render(string $view, array $data, User $user, string $title, string $currentPath, array $flash = [], int $status = 200): Response
    {
        $content = $this->include($view, $data);

        return $this->respond($this->include('layout', [
            'title' => $title,
            'content' => $content,
            'user' => $user,
            'menu' => $this->menu->forUser($user, $currentPath),
            'flash' => $flash,
            'siteName' => $this->siteName,
            'version' => $this->version,
            'signOutToken' => Csrf::token('admin_logout'),
            'assetVersion' => $this->version,
        ]), $status);
    }

    /**
     * A screen with no menu and no signed-in user, such as the sign-in page.
     *
     * @param array<string, mixed> $data
     */
    public function renderBare(string $view, array $data, string $title, int $status = 200): Response
    {
        return $this->respond($this->include('bare', [
            'title' => $title,
            'content' => $this->include($view, $data),
            'siteName' => $this->siteName,
            'assetVersion' => $this->version,
        ]), $status);
    }

    private function respond(string $html, int $status): Response
    {
        return Response::html($html, $status, ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function include(string $view, array $data): string
    {
        if (preg_match('/^[a-z][a-z0-9-]*\z/', $view) !== 1) {
            throw new InvalidArgumentException('Invalid view name.');
        }

        $file = $this->viewsPath . '/' . $view . '.php';

        if (!is_file($file)) {
            throw new InvalidArgumentException('Unknown view.');
        }

        // The view runs in a scope of its own that holds only the data it was given.
        $render = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();

            try {
                require $__file;
            } catch (\Throwable $exception) {
                ob_end_clean();

                throw $exception;
            }

            return (string) ob_get_clean();
        };

        return $render($file, $data);
    }
}
