<?php

/**
 * The form behind the theme switcher: a visitor picks one of the themes the Administrator offers.
 *
 * @package LumoraFiction
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.3.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers;

use LumoraFiction\Core\Http\BasePath;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ThemeChoice;

/**
 * The switcher works as a plain form, so there is no script to trust. Whatever goes wrong (a stale token, a name that is
 * not offered, the feature turned off) changes nothing and sends the visitor back to the page they were on. Where that is
 * comes from the form but is checked here to be a path on this site, so the form cannot be used to send anyone elsewhere.
 */
final class ThemeChoiceController
{
    public function __construct(
        private readonly ThemeChoice $choice,
        private readonly AuthService $auth,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function choose(array $params, Request $request): Response
    {
        $response = Response::redirect($this->returnPath($request->input('return') ?? ''), 303)->withHeader('Cache-Control', 'private, no-store');

        if (!$this->choice->validToken($request->input('token'), $request->ip())) {
            return $response;
        }

        $user = isset($request->cookies[$this->sessionName()]) ? $this->auth->user($request->cookies) : null;
        $result = $this->choice->choose($request->input('theme') ?? '', $user, $request->isHttps());

        return $result['cookie'] === null ? $response : $response->withHeader('Set-Cookie', $result['cookie']);
    }

    private function returnPath(string $path): string
    {
        return preg_match('#^/(?![/\\\\])[^\s"\'<>\\\\]{0,500}\z#', $path) === 1 ? $path : BasePath::get() . '/';
    }

    private function sessionName(): string
    {
        return \LumoraFiction\Core\Security\SessionManager::DEFAULT_NAME;
    }
}
