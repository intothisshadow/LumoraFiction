<?php

/**
 * Settings › Archive: what the site publishes, who may join, and what waits for approval.
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
use LumoraFiction\Models\User;
use LumoraFiction\Models\UserRole;
use LumoraFiction\Services\ArchiveSettings;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ClassificationPresets;
use LumoraFiction\Services\PresetException;
use LumoraFiction\Services\WholeStorySettings;

final class ArchiveSettingsController extends AdminController
{
    private const PATH = 'settings/archive';

    private const PRESET_TOKEN = 'admin_settings_presets';

    private const READING_TOKEN = 'admin_settings_reading';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly ArchiveSettings $settings,
        private readonly ClassificationPresets $presets,
        private readonly WholeStorySettings $wholeStory,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * The whole-story page switch and its word cap, saved by a form of their own so the archive form is untouched.
     *
     * @param array<string, string> $params
     */
    public function updateReading(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $result = $this->wholeStory->validate([
            'whole_story' => $request->checked('whole_story'),
            'whole_story_words' => (string) $request->input('whole_story_words'),
        ]);

        if (!$this->validToken($request, self::READING_TOKEN)) {
            return $this->page($request, $user, $this->settings->current(), [], 400, $result['values'], ['reading_form' => 'Your session expired, so nothing was saved. Please check the settings and save again.']);
        }

        if ($result['errors'] !== []) {
            return $this->page($request, $user, $this->settings->current(), [], 422, $result['values'], $result['errors']);
        }

        $changed = $this->wholeStory->save($result['values'], $user, $request->ip());

        $this->flash->add('success', $changed === [] ? 'Nothing needed changing.' : 'Reading settings saved.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * Adds the values of a starter list to a classification list. Choosing "No starter list" does nothing, so nothing is
     * ever applied by accident or required.
     *
     * @param array<string, string> $params
     */
    public function applyPreset(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $kind = $request->input('kind') ?? '';
        $preset = $request->input('preset') ?? '';

        if (!$this->validToken($request, self::PRESET_TOKEN)) {
            $this->flash->add('error', 'Your session expired, so nothing was added. Please try again.');
        } elseif ($preset === '') {
            $this->flash->add('info', 'No starter list was chosen, so nothing was added.');
        } else {
            try {
                $result = $this->presets->apply($user, $kind, $preset, $request->ip());
                $label = strtolower(ClassificationPresets::KIND_LABELS[$kind] ?? 'list');

                $this->flash->add('success', $kind === 'status'
                    ? 'The story status choices were updated.'
                    : sprintf('Added %d to %s; %d already there%s.', $result['created'], $label, $result['existing'], $result['skipped'] > 0 ? ', ' . $result['skipped'] . ' left out because their names clash with something else' : ''));
            } catch (PresetException $exception) {
                $this->flash->add('error', $exception->getMessage());
            }
        }

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        return $this->page($request, $user, $this->settings->current(), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $submitted = [
            'archive_mode' => $request->input('archive_mode'),
            'default_role' => $request->input('default_role'),
            'registration_enabled' => $request->checked('registration_enabled'),
            'email_verification' => $request->checked('email_verification'),
            'hold_first_story' => $request->checked('hold_first_story'),
            'require_signin' => $request->checked('require_signin'),
            'writer_tags' => $request->checked('writer_tags'),
        ];

        if (!$this->validToken($request, 'admin_settings_archive')) {
            return $this->page($request, $user, $this->settings->validate($submitted)['values'], ['form' => 'Your session expired, so nothing was saved. Please check the settings and save again.'], 400);
        }

        $result = $this->settings->validate($submitted);

        if ($result['errors'] !== []) {
            return $this->page($request, $user, $result['values'], $result['errors'], 422);
        }

        $changed = $this->settings->save($result['values'], $user, $request->ip());

        $this->flash->add('success', $changed === [] ? 'Nothing needed changing.' : 'Settings saved.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function page(Request $request, User $user, array $values, array $errors, int $status = 200, ?array $reading = null, array $readingErrors = []): Response
    {
        return $this->view->render('settings-archive', [
            'reading' => $reading ?? $this->wholeStory->current(),
            'readingErrors' => $readingErrors,
            'readingToken' => Csrf::token(self::READING_TOKEN),
            'readingAction' => AdminUrl::to(self::PATH . '/reading'),
            'wordLimits' => ['min' => WholeStorySettings::MIN_WORDS, 'max' => WholeStorySettings::MAX_WORDS],
            'values' => $values,
            'errors' => $errors,
            'modes' => ArchiveSettings::MODES,
            'roles' => array_map(static fn (UserRole $role): array => ['value' => $role->value, 'label' => $role->label()], UserRole::selfServiceRoles()),
            'csrfToken' => Csrf::token('admin_settings_archive'),
            'action' => AdminUrl::to(self::PATH),
            'catalogue' => $this->presets->catalogue(),
            'applied' => $this->presets->applied(),
            'presetUrl' => AdminUrl::to(self::PATH . '/presets'),
            'presetToken' => Csrf::token(self::PRESET_TOKEN),
        ], $user, 'Archive settings', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
