<?php

/**
 * Appearance: the installed themes, choosing the active one, installing, updating and deleting themes, and the active theme's options.
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
use LumoraFiction\Core\Theme\ThemeArchive;
use LumoraFiction\Core\Theme\ThemeInstallException;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Core\Theme\ThemeScreenshot;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ThemeManager;
use LumoraFiction\Services\ThemeOptions;
use LumoraFiction\Services\ThemeSettings;

final class AppearanceController extends AdminController
{
    private const THEMES_PATH = 'appearance';

    private const OPTIONS_PATH = 'appearance/options';

    private const STALE_FORM = 'Your session expired, so nothing was changed. Please try again.';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly ThemeRenderer $themes,
        private readonly ThemeSettings $settings,
        private readonly ThemeOptions $options,
        private readonly ThemeManager $manager,
        private readonly ThemeScreenshot $screenshots,
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
        $cards = [];

        foreach ([...array_values($catalog->installed()), $catalog->fallback()] as $theme) {
            $cards[] = [
                'theme' => $theme,
                'active' => $theme->slug === $active->slug,
                'screenshot' => $this->screenshots->locate($theme) === null
                    ? ''
                    : AdminUrl::to(self::THEMES_PATH . '/screenshot/' . $theme->slug) . '?v=' . rawurlencode($theme->version),
                'problems' => $this->manager->problemsFor($theme),
                'selectable' => !$theme->builtIn && !$this->manager->isInUse($theme->slug),
            ];
        }

        return $this->view->render('appearance-themes', [
            'cards' => $cards,
            'chosenMissing' => !$this->settings->chosenIsAvailable() ? $this->settings->chosenSlug() : '',
            'activateToken' => Csrf::token('admin_appearance_activate'),
            'installToken' => Csrf::token('admin_appearance_install'),
            'updateToken' => Csrf::token('admin_appearance_update'),
            'bulkToken' => Csrf::token('admin_appearance_bulk'),
            'activateAction' => AdminUrl::to(self::THEMES_PATH . '/activate'),
            'installAction' => AdminUrl::to(self::THEMES_PATH . '/install'),
            'updateAction' => AdminUrl::to(self::THEMES_PATH . '/update'),
            'bulkAction' => AdminUrl::to(self::THEMES_PATH . '/bulk'),
            'optionsUrl' => AdminUrl::to(self::OPTIONS_PATH),
            'uploadsAllowed' => $this->manager->uploadsAllowed(),
            'canWrite' => $this->manager->canWrite(),
            'maxUploadMegabytes' => intdiv(ThemeArchive::MAX_ARCHIVE_BYTES, 1048576),
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

        $slug = (string) $request->input('theme');
        $theme = $this->themes->catalog()->find($slug);

        if (!$this->validToken($request, 'admin_appearance_activate')) {
            $this->flash->add('error', self::STALE_FORM);
        } elseif ($theme !== null && $this->manager->problemsFor($theme) !== []) {
            $this->flash->add('error', '"' . $theme->name . '" cannot be used on this site. ' . implode(' ', $this->manager->problemsFor($theme)));
        } elseif ($this->settings->activate($slug, $user, $request->ip())) {
            $this->flash->add('success', 'The theme was changed.');
        } else {
            $this->flash->add('error', 'That theme is not available.');
        }

        return Response::redirect(AdminUrl::to(self::THEMES_PATH), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function install(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::THEMES_PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if ($this->exceedsServerLimit($request)) {
            $this->flash->add('error', 'That file is larger than this server accepts, so nothing was installed.');
        } elseif (!$this->validToken($request, 'admin_appearance_install')) {
            $this->flash->add('error', self::STALE_FORM);
        } else {
            try {
                $installed = $this->manager->install($request->upload('theme_zip'), $user, $request->ip());

                $this->flash->add('success', '"' . $installed['name'] . '" version ' . $installed['version'] . ' was installed. It is not in use yet; choose "Use this theme" when you are ready.');
            } catch (ThemeInstallException $exception) {
                $this->flash->add('error', $exception->getMessage());
            }
        }

        return Response::redirect(AdminUrl::to(self::THEMES_PATH), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::THEMES_PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if ($this->exceedsServerLimit($request)) {
            $this->flash->add('error', 'That file is larger than this server accepts, so nothing was changed.');
        } elseif (!$this->validToken($request, 'admin_appearance_update')) {
            $this->flash->add('error', self::STALE_FORM);
        } else {
            try {
                $updated = $this->manager->update($request->upload('theme_zip'), (string) $request->input('theme'), $request->checked('allow_older'), $user, $request->ip());

                $this->flash->add('success', $updated['from'] === $updated['to']
                    ? '"' . $updated['name'] . '" was reinstalled at version ' . $updated['to'] . '. Its saved options were kept.'
                    : '"' . $updated['name'] . '" was changed from version ' . $updated['from'] . ' to ' . $updated['to'] . '. Its saved options were kept.');
            } catch (ThemeInstallException $exception) {
                $this->flash->add('error', $exception->getMessage() . ' The theme was left as it was.');
            }
        }

        return Response::redirect(AdminUrl::to(self::THEMES_PATH), 303);
    }

    /**
     * Deleting asks for confirmation on a page of its own, so the first post only shows that page.
     *
     * @param array<string, string> $params
     */
    public function bulk(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::THEMES_PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $slugs = $request->inputList('themes');

        if (!$this->validToken($request, 'admin_appearance_bulk')) {
            $this->flash->add('error', self::STALE_FORM);
        } elseif ($request->input('bulk_action') !== 'delete') {
            $this->flash->add('error', 'Choose what to do with the selected themes.');
        } elseif ($slugs === []) {
            $this->flash->add('error', 'Tick at least one theme first.');
        } elseif (!$request->checked('confirmed')) {
            $plan = $this->manager->deletionPlan($slugs);

            if ($plan['deletable'] !== []) {
                return $this->confirmDeletePage($request, $user, $plan);
            }

            $this->flash->add('error', 'None of the selected themes can be deleted. ' . implode(' ', $plan['kept']));
        } else {
            $result = $this->manager->delete($slugs, $user, $request->ip());

            if ($result['deleted'] !== []) {
                $names = array_map(static fn ($theme): string => '"' . $theme->name . '"', $result['deleted']);

                $this->flash->add('success', (count($names) === 1 ? 'The theme ' : 'The themes ') . implode(', ', $names) . (count($names) === 1 ? ' was deleted.' : ' were deleted.'));
            }

            foreach ($result['kept'] as $slug => $reason) {
                $this->flash->add('warning', '"' . mb_substr((string) $slug, 0, 50) . '" was not deleted. ' . $reason);
            }
        }

        return Response::redirect(AdminUrl::to(self::THEMES_PATH), 303);
    }

    /**
     * A theme's preview picture, sent only to an administrator and only after its bytes were checked.
     *
     * @param array<string, string> $params
     */
    public function screenshot(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::THEMES_PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $theme = $this->themes->catalog()->find($params['slug'] ?? '');
        $picture = $theme === null ? null : $this->screenshots->locate($theme);
        $bytes = $picture === null ? false : file_get_contents($picture['path']);

        if ($picture === null || $bytes === false) {
            return Response::plain('Not found', 404);
        }

        return new Response($bytes, 200, [
            'Content-Type' => $picture['mime'],
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=600',
        ]);
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
     * PHP drops the whole form when a file is larger than post_max_size, so the token is missing too; telling that
     * apart from an expired session saves the administrator a confusing message.
     */
    private function exceedsServerLimit(Request $request): bool
    {
        return $request->post === [] && (int) ($request->server['CONTENT_LENGTH'] ?? 0) > 0;
    }

    /**
     * @param array{deletable: list<\LumoraFiction\Core\Theme\ThemeInfo>, kept: array<string, string>} $plan
     */
    private function confirmDeletePage(Request $request, User $user, array $plan): Response
    {
        return $this->view->render('appearance-bulk-delete', [
            'themes' => $plan['deletable'],
            'kept' => $plan['kept'],
            'anyBundled' => array_filter($plan['deletable'], static fn ($theme): bool => $theme->bundled) !== [],
            'csrfToken' => Csrf::token('admin_appearance_bulk'),
            'action' => AdminUrl::to(self::THEMES_PATH . '/bulk'),
            'backUrl' => AdminUrl::to(self::THEMES_PATH),
        ], $user, 'Delete themes', self::THEMES_PATH, $this->flash->pull($request->cookies));
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
