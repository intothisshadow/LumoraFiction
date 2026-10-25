<?php

/**
 * Maintenance › Backups: making, downloading, restoring and deleting backups of the files and the database.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers\Admin;

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Admin\AdminView;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Core\Update\UpdateException;
use LumoraFiction\Models\Capability;
use LumoraFiction\Services\AuditLogService;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\BackupService;
use LumoraFiction\Services\UpdateService;

final class BackupsController extends AdminController
{
    private const PATH = 'maintenance/backups';

    private const TOKEN = 'admin_backups';

    private const STALE_FORM = 'Your session expired, so nothing was changed. Please try again.';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly BackupService $backups,
        private readonly UpdateService $updates,
        private readonly AuditLogService $audit,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function index(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $records = $this->backups->all();
        $rows = [];

        foreach ($records as $record) {
            $id = (string) $record['id'];
            $rows[] = $record + [
                'complete' => ($record['status'] ?? '') === 'complete',
                'files_url' => AdminUrl::to(self::PATH . '/' . $id . '/download/files'),
                'database_url' => AdminUrl::to(self::PATH . '/' . $id . '/download/database'),
                'restore_url' => AdminUrl::to(self::PATH . '/' . $id . '/restore'),
                'delete_url' => AdminUrl::to(self::PATH . '/' . $id . '/delete'),
            ];
        }

        return $this->view->render('backups', [
            'backups' => $rows,
            'running' => $this->updates->current(),
            'csrfToken' => Csrf::token(self::TOKEN),
            'createAction' => AdminUrl::to(self::PATH . '/create'),
            'runUrl' => AdminUrl::to('maintenance/updates/run'),
            'updatesUrl' => AdminUrl::to('maintenance/updates'),
        ], $user, 'Backups', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $params
     */
    public function create(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);

            return $this->back();
        }

        try {
            $run = $this->updates->startBackup();
        } catch (UpdateException $exception) {
            $this->flash->add('error', $exception->getMessage());

            return $this->back();
        }

        $this->audit->record($user->id, 'backup.started', 'backup', $run->id, [], $request->ip());

        return Response::redirect(AdminUrl::to('maintenance/updates/run'), 303);
    }

    /**
     * A backup holds the password hashes and every private draft, so only an administrator may download one, and the
     * file is sent through the application: the storage folder is never served directly.
     *
     * @param array<string, string> $params
     */
    public function download(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $id = (string) ($params['id'] ?? '');
        $part = (string) ($params['part'] ?? '');
        $path = $this->backups->pathFor($id, $part);

        if ($path === null) {
            return $this->missing();
        }

        $this->audit->record($user->id, 'backup.downloaded', 'backup', $id, ['part' => $part], $request->ip());

        $name = basename($path);
        $type = str_ends_with($name, '.zip') ? 'application/zip' : (str_ends_with($name, '.gz') ? 'application/gzip' : 'application/sql');

        return Response::file($path, $name, $type);
    }

    /**
     * @param array<string, string> $params
     */
    public function showRestore(array $params, Request $request): Response
    {
        return $this->confirmation($params, $request, 'restore');
    }

    /**
     * @param array<string, string> $params
     */
    public function showDelete(array $params, Request $request): Response
    {
        return $this->confirmation($params, $request, 'delete');
    }

    /**
     * @param array<string, string> $params
     */
    public function restore(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $id = (string) ($params['id'] ?? '');

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);

            return $this->back();
        }

        try {
            $run = $this->updates->startRestore($id);
        } catch (UpdateException $exception) {
            $this->flash->add('error', $exception->getMessage());

            return $this->back();
        }

        $this->audit->record($user->id, 'backup.restore_started', 'backup', $id, ['run' => $run->id], $request->ip());

        return Response::redirect(AdminUrl::to('maintenance/updates/run'), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $id = (string) ($params['id'] ?? '');

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);
        } elseif ($this->updates->current()?->backupId === $id) {
            $this->flash->add('error', 'That backup is in use by the update in progress, so it cannot be deleted.');
        } elseif ($this->backups->delete($id)) {
            $this->audit->record($user->id, 'backup.deleted', 'backup', $id, [], $request->ip());
            $this->flash->add('success', 'The backup was deleted.');
        } else {
            $this->flash->add('error', 'That backup no longer exists.');
        }

        return $this->back();
    }

    /**
     * Restoring and deleting both ask on a page of their own, so neither can happen from a stray click or a link.
     *
     * @param array<string, string> $params
     */
    private function confirmation(array $params, Request $request, string $action): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $record = $this->backups->find((string) ($params['id'] ?? ''));

        if ($record === null) {
            return $this->missing();
        }

        return $this->view->render('backup-confirm', [
            'backup' => $record,
            'complete' => ($record['status'] ?? '') === 'complete',
            'action' => $action,
            'csrfToken' => Csrf::token(self::TOKEN),
            'formAction' => AdminUrl::to(self::PATH . '/' . $record['id'] . '/' . $action),
            'backUrl' => AdminUrl::to(self::PATH),
        ], $user, $action === 'restore' ? 'Restore this backup' : 'Delete this backup', self::PATH, $this->flash->pull($request->cookies));
    }

    private function missing(): Response
    {
        $this->flash->add('error', 'That backup does not exist.');

        return $this->back();
    }

    private function back(): Response
    {
        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }
}
