<?php

/**
 * Appearance: the installed themes, choosing the active one, and the active theme's options.
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
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ThemeOptions;
use LumoraFiction\Services\ThemeSettings;

final class AppearanceController extends AdminController
{
    private const THEMES_PATH = 'appearance';

    private const OPTIONS_PATH = 'appearance/options';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly ThemeRenderer $themes,
        private readonly ThemeSettings $settings,
        private readonly ThemeOptions $options,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function themes(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::THEMES_PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $active = $this->themes->activeTheme();
        $catalog = $this->themes->catalog();

        return $this->view->render('appearance-themes', [
            'themes' => [...array_values($catalog->installed()), $catalog->fallback()],
            'activeSlug' => $active->slug,
            'chosenMissing' => !$this->settings->chosenIsAvailable() ? $this->settings->chosenSlug() : '',
            'csrfToken' => Csrf::token('admin_appearance_activate'),
            'action' => AdminUrl::to(self::THEMES_PATH . '/activate'),
            'optionsUrl' => AdminUrl::to(self::OPTIONS_PATH),
        ], $user, 'Themes', self::THEMES_PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $params
     */
    public function activate(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::THEMES_PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, 'admin_appearance_activate')) {
            $this->flash->add('error', 'Your session expired, so nothing was changed. Please try again.');
        } elseif ($this->settings->activate((string) $request->input('theme'), $user, $request->ip())) {
            $this->flash->add('success', 'The theme was changed.');
        } else {
            $this->flash->add('error', 'That theme is not available.');
        }

        return Response::redirect(AdminUrl::to(self::THEMES_PATH), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function showOptions(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::OPTIONS_PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $theme = $this->themes->activeTheme();
        $this->themes->bootTheme($theme);

        return $this->optionsPage($request, $user, $this->options->values($theme->slug), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function saveOptions(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::OPTIONS_PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $theme = $this->themes->activeTheme();
        $this->themes->bootTheme($theme);

        $input = [];

        foreach ($this->options->fields() as $field) {
            $name = 'opt_' . $field['key'];

            $input[$field['key']] = match ($field['type']) {
                'checkbox' => $request->checked($name),
                // A colour input always submits something, so a separate box says "use the theme's own colour".
                'color' => $request->checked('inherit_' . $field['key']) ? '' : (string) $request->input($name),
                default => (string) $request->input($name),
            };
        }

        $result = $this->options->validate($theme->slug, $input);

        if (!$this->validToken($request, 'admin_appearance_options')) {
            return $this->optionsPage($request, $user, $result['values'], ['form' => 'Your session expired, so nothing was saved. Please check the options and save again.'], 400);
        }

        if ($result['errors'] !== []) {
            return $this->optionsPage($request, $user, $result['values'], $result['errors'], 422);
        }

        $changed = $this->options->save($theme->slug, $result['values'], $user, $request->ip());

        $this->flash->add('success', $changed === [] ? 'Nothing needed changing.' : 'Theme options saved.');

        return Response::redirect(AdminUrl::to(self::OPTIONS_PATH), 303);
    }

    /**
     * @param array<string, string|bool> $values
     * @param array<string, string> $errors
     */
    private function optionsPage(Request $request, User $user, array $values, array $errors, int $status = 200): Response
    {
        $theme = $this->themes->activeTheme();

        return $this->view->render('appearance-options', [
            'themeName' => $theme->name,
            'fields' => $this->options->fields(),
            'values' => $values,
            'errors' => $errors,
            'csrfToken' => Csrf::token('admin_appearance_options'),
            'action' => AdminUrl::to(self::OPTIONS_PATH),
        ], $user, 'Theme options', self::OPTIONS_PATH, $this->flash->pull($request->cookies), $status);
    }
}
