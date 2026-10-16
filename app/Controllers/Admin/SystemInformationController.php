<?php

/**
 * Maintenance › System Information: what the server offers, and a snapshot to send when asking for help.
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
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Models\Capability;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\SystemInformationService;

final class SystemInformationController extends AdminController
{
    private const PATH = 'maintenance/system-information';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly string $root,
        private readonly Configuration $config,
        private readonly Database $database,
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

        return $this->view->render('system-information', [
            'sections' => $this->service($request)->sections(),
            'downloadUrl' => AdminUrl::to(self::PATH . '.json'),
        ], $user, 'System Information', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $params
     */
    public function download(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $snapshot = ['generated_at_utc' => gmdate('Y-m-d H:i'), 'information' => $this->service($request)->snapshot()];

        return Response::download(
            (string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'lumora-fiction-system-information-' . gmdate('Ymd-Hi') . '.json',
            'application/json; charset=utf-8',
        )->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function service(Request $request): SystemInformationService
    {
        return new SystemInformationService($this->root, $this->config, $this->database, $request->server);
    }
}
