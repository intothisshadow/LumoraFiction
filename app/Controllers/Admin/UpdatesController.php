<?php

/**
 * Maintenance › Updates: checking for a release, reviewing a package, and carrying an update out step by step.
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
use LumoraFiction\Core\Update\UpdateRun;
use LumoraFiction\Core\Update\UpdateStorage;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuditLogService;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\UpdateChecker;
use LumoraFiction\Services\UpdateService;

final class UpdatesController extends AdminController
{
    public const PATH = 'maintenance/updates';

    private const TOKEN = 'admin_updates';

    private const STALE_FORM = 'Your session expired, so nothing was changed. Please try again.';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly UpdateService $updates,
        private readonly UpdateChecker $checker,
        private readonly UpdateStorage $storage,
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

        return $this->page($request, $user, ['repository' => $this->checker->settings()['repository'], 'channel' => $this->checker->settings()['channel']], []);
    }

    /**
     * @param array<string, string> $params
     */
    public function saveSettings(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $result = $this->checker->validate(['repository' => $request->input('repository'), 'channel' => $request->input('channel')]);

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->page($request, $user, $result['values'], ['form' => self::STALE_FORM], 400);
        }

        if ($result['errors'] !== []) {
            return $this->page($request, $user, $result['values'], $result['errors'], 422);
        }

        $changed = $this->checker->save($result['values'], $user, $request->ip());
        $this->flash->add('success', $changed === [] ? 'Nothing needed changing.' : 'Update settings saved.');

        return $this->back();
    }

    /**
     * @param array<string, string> $params
     */
    public function check(array $params, Request $request): Response
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
            $answer = $this->checker->check();

            if ($answer['release'] === null) {
                $this->flash->add('info', 'GitHub has no release of Lumora Fiction to offer on this channel.');
            } elseif ($answer['newer']) {
                $this->flash->add('success', 'Version ' . $answer['release']['version'] . ' is available.');
            } else {
                $this->flash->add('success', 'You are running the newest release.');
            }
        } catch (UpdateException $exception) {
            $this->flash->add('error', $exception->getMessage());
        }

        return $this->back();
    }

    /**
     * Fetches the release the last check found and goes to the review screen.
     *
     * @param array<string, string> $params
     */
    public function download(array $params, Request $request): Response
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
            $this->updates->discardPackage();
            $this->checker->download();
            $this->updates->registerPackage('github', true);
        } catch (UpdateException $exception) {
            $this->flash->add('error', $exception->getMessage());

            return $this->back();
        }

        return Response::redirect(AdminUrl::to(self::PATH . '/review'), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function upload(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        // A file bigger than the server accepts arrives as an empty form.
        if ($request->post === [] && (int) ($request->server['CONTENT_LENGTH'] ?? 0) > 0) {
            $this->flash->add('error', 'That file is larger than this server accepts. Raise upload_max_filesize and post_max_size, or use the GitHub download.');

            return $this->back();
        }

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);

            return $this->back();
        }

        $file = $request->upload('package');

        if ($file === null || !$file->wasSent()) {
            $this->flash->add('error', 'Choose the release ZIP to upload.');

            return $this->back();
        }

        if ($file->problem() !== '') {
            $this->flash->add('error', $file->problem());

            return $this->back();
        }

        try {
            $this->updates->receiveUpload($file->path, $request->input('checksum'));
        } catch (UpdateException $exception) {
            $this->flash->add('error', $exception->getMessage());

            return $this->back();
        }

        return Response::redirect(AdminUrl::to(self::PATH . '/review'), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function review(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $review = $this->updates->review();

        if ($review === null) {
            $this->flash->add('info', 'There is no update package waiting for review.');

            return $this->back();
        }

        return $this->view->render('update-review', [
            'review' => $review,
            'from' => $this->updates->installedVersion(),
            'csrfToken' => Csrf::token(self::TOKEN),
            'startAction' => AdminUrl::to(self::PATH . '/start'),
            'discardAction' => AdminUrl::to(self::PATH . '/discard'),
            'backToUpdates' => AdminUrl::to(self::PATH),
        ], $user, 'Review the update', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $params
     */
    public function discard(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);
        } elseif ($this->updates->current() !== null) {
            $this->flash->add('error', 'An update is in progress, so its package cannot be thrown away.');
        } else {
            $this->updates->discardPackage();
            $this->flash->add('success', 'The package was thrown away. Nothing was changed.');
        }

        return $this->back();
    }

    /**
     * @param array<string, string> $params
     */
    public function start(array $params, Request $request): Response
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
            $run = $this->updates->startUpdate();
        } catch (UpdateException $exception) {
            $this->flash->add('error', $exception->getMessage());

            return Response::redirect(AdminUrl::to(self::PATH . '/review'), 303);
        }

        $this->audit->record($user->id, 'update.started', 'update', $run->id, ['from' => $run->fromVersion, 'to' => $run->toVersion, 'source' => $run->source], $request->ip());

        return $this->runPage();
    }

    /**
     * The progress screen for whatever is running: an update, a restore or a backup.
     *
     * @param array<string, string> $params
     */
    public function run(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $run = $this->updates->current();

        if ($run === null) {
            return $this->back();
        }

        return $this->view->render('update-run', [
            'run' => $run,
            'steps' => $this->updates->progress($run),
            'log' => array_slice($run->log, -12),
            'csrfToken' => Csrf::token(self::TOKEN),
            'advanceAction' => AdminUrl::to(self::PATH . '/run/advance'),
            'cancelAction' => AdminUrl::to(self::PATH . '/run/cancel'),
            'autoAdvance' => in_array($run->status, [UpdateRun::RUNNING, UpdateRun::ROLLING_BACK], true),
            'rollingBack' => $run->status === UpdateRun::ROLLING_BACK,
        ], $user, ucfirst($run->kind) . ' in progress', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $params
     */
    public function advance(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $json = $request->wantsJson();

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);

            return $json ? $this->jsonRedirect(self::PATH . '/run', 403) : $this->runPage();
        }

        try {
            $run = $this->updates->advance();
        } catch (UpdateException $exception) {
            $this->flash->add('error', $exception->getMessage());

            return $json ? $this->jsonRedirect(self::PATH . '/run') : $this->runPage();
        }

        if (!$json) {
            return $this->afterStep($run);
        }

        // A finished run reports through the same flash message the form flow uses, then sends the page back.
        if ($run->isTerminal()) {
            $this->flashOutcome($run);

            return $this->jsonRedirect(self::PATH);
        }

        return $this->json($this->progressPayload($run));
    }

    /**
     * @param array<string, string> $params
     */
    public function cancel(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);

            return $this->runPage();
        }

        try {
            $run = $this->updates->cancel();
            $this->audit->record($user->id, 'update.cancel_requested', 'update', $run->id, [], $request->ip());
        } catch (UpdateException $exception) {
            $this->flash->add('error', $exception->getMessage());

            return $this->runPage();
        }

        return $this->afterStep($run);
    }

    /**
     * @param array<string, string> $params
     */
    public function clearMaintenance(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);
        } else {
            $this->updates->clearStrandedMaintenance();
            $this->flash->add('success', 'The site is open to visitors again.');
        }

        return $this->back();
    }

    /**
     * A finished run goes back to the Updates screen with its outcome; one still going stays on the progress screen.
     */
    private function afterStep(UpdateRun $run): Response
    {
        if (!$run->isTerminal()) {
            return $this->runPage();
        }

        $this->flashOutcome($run);

        return $this->back();
    }

    private function flashOutcome(UpdateRun $run): void
    {
        $type = match ($run->status) {
            UpdateRun::COMPLETE => 'success',
            UpdateRun::ROLLED_BACK => 'error',
            default => 'info',
        };
        $text = match ($run->status) {
            UpdateRun::COMPLETE => match ($run->kind) {
                UpdateRun::KIND_UPDATE => 'Lumora Fiction was updated to version ' . $run->toVersion . '.',
                UpdateRun::KIND_RESTORE => 'The backup was restored.',
                default => 'The backup was created.',
            },
            UpdateRun::ROLLED_BACK => 'The ' . $run->kind . ' did not succeed and the site was put back as it was. ' . $run->message,
            default => 'The ' . $run->kind . ' was cancelled. ' . ($run->message === 'Cancelled by an administrator.' ? '' : $run->message),
        };

        $this->flash->add($type, trim($text));
    }

    /**
     * What the progress page needs to redraw itself after a step. The token is fresh because each one is single-use.
     *
     * @return array<string, mixed>
     */
    private function progressPayload(UpdateRun $run): array
    {
        $log = [];

        foreach (array_slice($run->log, -12) as $line) {
            $log[] = ['text' => gmdate('H:i:s', (int) $line['time']) . ' UTC — ' . $line['text'], 'ok' => (bool) $line['ok']];
        }

        return [
            'status' => $run->status,
            'steps' => $this->updates->progress($run),
            'log' => $log,
            'message' => $run->message,
            'token' => Csrf::token(self::TOKEN),
        ];
    }

    /**
     * Tells the script to load a page itself, which is how it falls back to what the server draws.
     */
    private function jsonRedirect(string $path, int $status = 200): Response
    {
        return $this->json(['status' => 'redirect', 'redirect' => AdminUrl::to($path)], $status);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): Response
    {
        return new Response(
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow'],
        );
    }

    private function runPage(): Response
    {
        return Response::redirect(AdminUrl::to(self::PATH . '/run'), 303);
    }

    private function back(): Response
    {
        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array{repository: string, channel: string} $values
     * @param array<string, string> $errors
     */
    private function page(Request $request, User $user, array $values, array $errors, int $status = 200): Response
    {
        $last = $this->checker->last();

        return $this->view->render('updates', [
            'version' => $this->updates->installedVersion(),
            'values' => $values,
            'errors' => $errors,
            'last' => $last,
            'run' => $this->updates->current(),
            'review' => is_file($this->storage->packagePath()) && is_file($this->storage->packageInfoPath()),
            'stranded' => $this->updates->strandedMaintenance(),
            'history' => $this->updates->history(),
            'logLines' => $this->logTail(),
            'canUpload' => class_exists(\ZipArchive::class),
            'csrfToken' => Csrf::token(self::TOKEN),
            'settingsAction' => AdminUrl::to(self::PATH . '/settings'),
            'checkAction' => AdminUrl::to(self::PATH . '/check'),
            'downloadAction' => AdminUrl::to(self::PATH . '/download'),
            'uploadAction' => AdminUrl::to(self::PATH . '/upload'),
            'clearAction' => AdminUrl::to(self::PATH . '/maintenance/clear'),
            'reviewUrl' => AdminUrl::to(self::PATH . '/review'),
            'runUrl' => AdminUrl::to(self::PATH . '/run'),
            'backupsUrl' => AdminUrl::to('maintenance/backups'),
            'maintenanceUrl' => AdminUrl::to('maintenance/mode'),
        ], $user, 'Updates', self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @return list<string> the last lines of the update log
     */
    private function logTail(): array
    {
        $path = $this->storage->logPath();

        if (!is_file($path) || filesize($path) === 0) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_slice(array_reverse($lines), 0, 30);
    }
}
