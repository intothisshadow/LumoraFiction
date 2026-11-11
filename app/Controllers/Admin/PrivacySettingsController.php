<?php

/**
 * Settings › Privacy: the privacy policy page, what happens to the stories of a deleted account, and the anonymous install ping.
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

use Closure;
use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Admin\AdminView;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\InstallPingService;
use LumoraFiction\Services\PageService;
use LumoraFiction\Services\PrivacySettings;
use RuntimeException;

final class PrivacySettingsController extends AdminController
{
    private const PATH = 'settings/privacy';

    private const TOKEN = 'admin_settings_privacy';

    /**
     * @param Closure(): InstallPingService $ping asked for each time, so the service the kernel holds is the one used
     */
    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly PrivacySettings $settings,
        private readonly PageService $pages,
        private readonly Closure $ping,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        return $user instanceof Response ? $user : $this->page($request, $user, $this->settings->current(), []);
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

        $result = $this->settings->validate([
            'page_id' => $request->input('page_id'),
            'deleted_stories' => $request->input('deleted_stories'),
            'neutral_name' => $request->input('neutral_name'),
            'ping_enabled' => $request->input('ping_enabled') === '1',
        ]);

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->page($request, $user, $result['values'], ['form' => 'Your session expired, so nothing was saved. Please check the settings and save again.'], 400);
        }

        if ($result['errors'] !== []) {
            return $this->page($request, $user, $result['values'], $result['errors'], 422);
        }

        $changed = $this->settings->save($result['values'], $user, $request->ip());

        $this->flash->add('success', $changed === [] ? 'Nothing needed changing.' : 'Settings saved.');

        // Straight after the switch is turned on, so the person sees it work; a failure is not worth an error here.
        if (in_array('ping.enabled', $changed, true)) {
            ($this->ping)()->sendAfterEnabling();
        }

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function testPing(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', 'Your session expired, so no ping was sent. Please try again.');

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        // Nothing leaves this site unless the switch is on.
        if (!($this->ping)()->isEnabled()) {
            $this->flash->add('error', 'Turn the anonymous install ping on and save first, then send a test ping.');

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        try {
            ($this->ping)()->sendPing();
            $this->flash->add('success', 'The test ping was sent and accepted.');
        } catch (RuntimeException $exception) {
            $this->flash->add('error', $exception->getMessage());
        }

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array{page_id: int, deleted_stories: string, neutral_name: string, ping_enabled: bool} $values
     * @param array<string, string> $errors
     */
    private function page(Request $request, User $user, array $values, array $errors, int $status = 200): Response
    {
        return $this->view->render('settings-privacy', [
            'values' => $values,
            'errors' => $errors,
            'pageChoices' => $this->pages->choices(),
            'storyChoices' => PrivacySettings::STORY_CHOICES,
            'nameMax' => PrivacySettings::NAME_MAX,
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH),
            'pagesUrl' => AdminUrl::to('pages'),
            'requestsUrl' => AdminUrl::to('maintenance/privacy'),
            'testPingUrl' => AdminUrl::to(self::PATH . '/ping-test'),
            'installId' => ($this->ping)()->currentUuid(),
            'lastPing' => ($this->ping)()->lastAttempt(),
        ], $user, 'Privacy settings', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
