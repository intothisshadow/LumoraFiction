<?php

/**
 * Settings > Export and import: save the portable settings to a file, or read one in with a preview first.
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
use LumoraFiction\Services\SettingsTransfer;
use LumoraFiction\Services\SettingsTransferException;

/**
 * The export is a POST, so its address can never be linked or prefetched. Importing is two steps: the file is read and
 * shown as a before-and-after list, and the page that shows it carries the file's text so the second step can apply it.
 * That text is checked from scratch when it comes back, so nothing about the preview has to be trusted.
 */
final class SettingsTransferController extends AdminController
{
    private const PATH = 'settings/transfer';

    private const TOKEN = 'admin_settings_transfer';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly SettingsTransfer $transfer,
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

        return $this->page($request, $user, null, []);
    }

    /**
     * @param array<string, string> $params
     */
    public function export(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->page($request, $user, null, ['form' => 'Your session expired. Please try again.'], 400);
        }

        return Response::download($this->transfer->exportJson(), 'lumora-fiction-settings-' . gmdate('Y-m-d') . '.json', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @param array<string, string> $params
     */
    public function preview(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->page($request, $user, null, ['form' => 'Your session expired. Please choose the file again.'], 400);
        }

        $upload = $request->upload('settings_file');

        if ($upload === null || !$upload->wasSent()) {
            return $this->page($request, $user, null, ['settings_file' => 'Choose a settings file to read.'], 422);
        }

        if ($upload->problem() !== '') {
            return $this->page($request, $user, null, ['settings_file' => $upload->problem()], 422);
        }

        if ($upload->size > SettingsTransfer::MAX_BYTES) {
            return $this->page($request, $user, null, ['settings_file' => 'That file is too large to be a settings file.'], 422);
        }

        $json = file_get_contents($upload->path);

        if ($json === false) {
            return $this->page($request, $user, null, ['settings_file' => 'The file could not be read.'], 422);
        }

        return $this->previewPage($request, $user, $json);
    }

    /**
     * @param array<string, string> $params
     */
    public function apply(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->page($request, $user, null, ['form' => 'Your session expired, so nothing was changed. Please choose the file again.'], 400);
        }

        try {
            $changed = $this->transfer->apply($user, (string) $request->input('payload'), $request->ip());
        } catch (SettingsTransferException $exception) {
            return $this->page($request, $user, null, ['settings_file' => $exception->getMessage()], 422);
        }

        $this->flash->add('success', $changed === 0 ? 'Nothing needed changing.' : 'Settings imported: ' . $changed . ($changed === 1 ? ' setting' : ' settings') . ' changed.');

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    private function previewPage(Request $request, User $user, string $json): Response
    {
        try {
            $preview = $this->transfer->preview($json);
        } catch (SettingsTransferException $exception) {
            return $this->page($request, $user, null, ['settings_file' => $exception->getMessage()], 422);
        }

        return $this->page($request, $user, ['preview' => $preview, 'payload' => $json], [], $preview['errors'] === [] ? 200 : 422);
    }

    /**
     * @param array{preview: array<string, mixed>, payload: string}|null $import
     * @param array<string, string> $errors
     */
    private function page(Request $request, User $user, ?array $import, array $errors, int $status = 200): Response
    {
        return $this->view->render('settings-transfer', [
            'import' => $import,
            'errors' => $errors,
            'exportAction' => AdminUrl::to(self::PATH . '/export'),
            'previewAction' => AdminUrl::to(self::PATH . '/preview'),
            'applyAction' => AdminUrl::to(self::PATH . '/apply'),
            'cancelUrl' => AdminUrl::to(self::PATH),
            'maxKilobytes' => intdiv(SettingsTransfer::MAX_BYTES, 1024),
            'csrfToken' => Csrf::token(self::TOKEN),
        ], $user, 'Export and import settings', self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
