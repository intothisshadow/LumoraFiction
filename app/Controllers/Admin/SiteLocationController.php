<?php

/**
 * The screen that offers to correct the site address after a move, and to keep the old one.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers\Admin;

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Admin\AdminView;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\SiteLocation;
use RuntimeException;

/**
 * The proposed address comes from the request the administrator is making and is only a suggestion in an editable
 * field; nothing is written until it is confirmed with a valid token, and then only through SiteLocation.
 */
final class SiteLocationController extends AdminController
{
    private const PATH = 'maintenance/site-location';

    private const TOKEN = 'admin_site_location';

    private const STALE_FORM = 'Your session expired, so nothing was changed. Please check the form and send it again.';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly SiteLocation $location,
        private readonly Configuration $config,
    ) {
        parent::__construct($auth, $view, $flash);
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

        return $this->page($request, $user, null, [], null, 200);
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

        $choice = $request->input('choice') ?? '';
        $typed = $request->input('address') ?? '';

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->page($request, $user, $typed, ['form' => self::STALE_FORM], null, 400);
        }

        if ($choice === 'keep') {
            $this->flash->add($this->location->keep($user->id, $request) ? 'success' : 'error', $this->location->currentAddress($request) !== null ? 'This address will not be flagged again. You can undo that below.' : 'The address you are using could not be read, so nothing was kept.');

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        if ($choice === 'reset') {
            $this->location->resetKept($user->id, $request->ip());
            $this->flash->add('success', 'Kept addresses were cleared. The site will say so again if it is opened on a different address.');

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        try {
            $address = $this->location->validAddress($typed);
        } catch (RuntimeException $exception) {
            return $this->page($request, $user, $typed, ['address' => $exception->getMessage()], null, 422);
        }

        if (!$this->location->confirm($user->id, $address, $request->ip())) {
            // Nothing was changed; the exact line is shown so the file can be edited by hand.
            return $this->page($request, $user, $address, [], SiteLocation::manualLine($address), 200);
        }

        $this->flash->add('success', 'The site address and folder were updated.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, string> $errors
     */
    private function page(Request $request, User $user, ?string $typed, array $errors, ?string $manualLine, int $status): Response
    {
        $mismatch = $this->location->mismatch($request);
        $current = $this->location->currentAddress($request);
        $record = $this->location->record();

        return $this->view->render('site-location', [
            'configured' => (string) $this->config->get('site_url', ''),
            'current' => $current ?? '',
            'record' => $record ?? ['url' => '', 'path' => ''],
            'currentPath' => $mismatch['currentPath'] ?? (string) (realpath(dirname(__DIR__, 3)) ?: ''),
            'mismatch' => $mismatch,
            'proposed' => $typed ?? $current ?? '',
            'kept' => $this->location->kept(),
            'writable' => $this->location->configWritable(),
            'manualLine' => $manualLine,
            'errors' => $errors,
            'errorSummary' => '',
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH),
        ], $user, 'Site address and folder', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
