<?php

/**
 * The screens for importing your own stories from another archive: upload, preview, report and undo.
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
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Import\GeneralHtmlParser;
use LumoraFiction\Core\Import\ImportException;
use LumoraFiction\Core\Import\UploadReader;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ImportService;
use LumoraFiction\Services\ImportSettings;

/**
 * Permission is the right to write stories while importing is switched on and open to the person, checked here on every route
 * and again inside the service. Every token in an address belongs to one account: another person's token answers the same
 * way as one that never existed. Every change is a POST with a form token.
 */
final class ImportController extends WriterController
{
    private const PATH = 'import';

    private const TOKEN = 'admin_import';

    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly ImportService $imports,
        private readonly Configuration $config,
    ) {
        parent::__construct($auth, $view, $flash);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(array $params, Request $request): Response
    {
        $user = $this->open($request);

        if ($user instanceof Response) {
            return $user;
        }

        return $this->uploadPage($request, $user, '', [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function upload(array $params, Request $request): Response
    {
        $user = $this->open($request);

        if ($user instanceof Response) {
            return $user;
        }

        $pseuds = (string) $request->input('pseuds');

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->uploadPage($request, $user, $pseuds, ['form' => self::STALE_FORM], 400);
        }

        try {
            $token = $this->imports->begin($user, $request->uploads('work'), $pseuds, [
                'general' => $request->checked('force_general'),
                'split' => (string) $request->input('split'),
                'combine' => $request->input('combine') !== 'separate',
            ]);
        } catch (ImportException $exception) {
            return $this->uploadPage($request, $user, $pseuds, ['work' => $exception->getMessage()], 422);
        }

        return Response::redirect(AdminUrl::to(self::PATH . '/preview/' . $token), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function preview(array $params, Request $request): Response
    {
        $user = $this->open($request);

        if ($user instanceof Response) {
            return $user;
        }

        return $this->previewPage($request, $user, $params['token'] ?? '', [], 200);
    }

    /**
     * Cuts one general file into chapters again at another kind of heading. The page comes back with everything the
     * author had typed still in place, except the order of the chapters of the file that was cut, which no longer applies.
     *
     * @param array<string, string> $params
     */
    public function resplit(array $params, Request $request): Response
    {
        $user = $this->open($request);

        if ($user instanceof Response) {
            return $user;
        }

        $token = $params['token'] ?? '';
        $index = (string) $request->input('resplit');

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->previewPage($request, $user, $token, ['form' => self::STALE_FORM], 400);
        }

        $posted = $request->post;
        $mode = is_array($posted['split'] ?? null) && is_string($posted['split'][$index] ?? null) ? $posted['split'][$index] : 'none';

        try {
            $this->imports->resplit($user, $token, ctype_digit($index) ? (int) $index : -1, $mode);
        } catch (ImportException $exception) {
            return $this->previewPage($request, $user, $token, ['form' => $exception->getMessage()], 422);
        }

        if (is_array($posted['order'] ?? null)) {
            unset($posted['order'][$index]);
        }

        return $this->previewPage($request, $user, $token, [], 200, $posted);
    }

    /**
     * @param array<string, string> $params
     */
    public function apply(array $params, Request $request): Response
    {
        $user = $this->open($request);

        if ($user instanceof Response) {
            return $user;
        }

        $token = $params['token'] ?? '';

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->previewPage($request, $user, $token, ['form' => self::STALE_FORM], 400);
        }

        if (!$request->checked('own_work')) {
            return $this->previewPage($request, $user, $token, ['own_work' => 'Confirm that these are your own stories, or that you have their author\'s permission.'], 422);
        }

        try {
            $run = $this->imports->apply($user, $token, $this->choices($request), $request->ip());
        } catch (ImportException $exception) {
            return $this->previewPage($request, $user, $token, ['form' => $exception->getMessage()], 422);
        }

        return Response::redirect(AdminUrl::to(self::PATH . '/run/' . $run), 303);
    }

    /**
     * The page a running import shows. It asks for the next step by itself, and says where it stands if the script is off.
     *
     * @param array<string, string> $params
     */
    public function progress(array $params, Request $request): Response
    {
        $user = $this->open($request);

        if ($user instanceof Response) {
            return $user;
        }

        $progress = $this->imports->progress($user, $params['token'] ?? '');

        if ($progress === null) {
            return $this->missing('That import is not available.', self::PATH);
        }

        if ($progress['state'] !== 'running') {
            return Response::redirect(AdminUrl::to(self::PATH . '/report/' . $progress['token']), 303);
        }

        return $this->view->render('import-progress', [
            'progress' => $progress,
            'csrfToken' => Csrf::token(self::TOKEN),
            'stepUrl' => AdminUrl::to(self::PATH . '/run/' . $progress['token']),
            'stopUrl' => AdminUrl::to(self::PATH . '/run/' . $progress['token'] . '/stop'),
        ], $user, 'Importing', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $params
     */
    public function step(array $params, Request $request): Response
    {
        $user = $this->open($request);

        if ($user instanceof Response) {
            return $user;
        }

        $token = (string) ($params['token'] ?? '');

        if (!$this->validToken($request, self::TOKEN)) {
            $this->flash->add('error', self::STALE_FORM);

            return Response::redirect(AdminUrl::to(self::PATH . '/run/' . $token), 303);
        }

        try {
            $this->imports->step($user, $token);
        } catch (ImportException $exception) {
            return $this->missing($exception->getMessage(), self::PATH);
        }

        return Response::redirect(AdminUrl::to(self::PATH . '/run/' . $token), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function stop(array $params, Request $request): Response
    {
        $user = $this->open($request);

        if ($user instanceof Response) {
            return $user;
        }

        $token = (string) ($params['token'] ?? '');

        if ($this->validToken($request, self::TOKEN)) {
            try {
                $this->imports->cancel($user, $token, $request->ip());
                $this->flash->add('success', 'The import was stopped. What was written so far is kept, and can still be undone.');
            } catch (ImportException $exception) {
                return $this->missing($exception->getMessage(), self::PATH);
            }
        }

        return Response::redirect(AdminUrl::to(self::PATH . '/report/' . $token), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function cancel(array $params, Request $request): Response
    {
        $user = $this->open($request);

        if ($user instanceof Response) {
            return $user;
        }

        if ($this->validToken($request, self::TOKEN)) {
            $this->imports->discard($user, $params['token'] ?? '');
            $this->flash->add('success', 'The import was cancelled and the uploaded files were removed.');
        }

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function report(array $params, Request $request): Response
    {
        $user = $this->open($request);

        if ($user instanceof Response) {
            return $user;
        }

        $report = $this->imports->report($user, $params['token'] ?? '');

        if ($report === null) {
            return $this->missing('That import report is not available.', self::PATH);
        }

        if ($report['state'] === 'running') {
            return Response::redirect(AdminUrl::to(self::PATH . '/run/' . $report['token']), 303);
        }

        return $this->view->render('import-report', [
            'report' => $report,
            'downloadUrl' => AdminUrl::to(self::PATH . '/report/' . $report['token'] . '/download'),
            'undoUrl' => AdminUrl::to(self::PATH . '/report/' . $report['token'] . '/undo'),
            'storyUrl' => AdminUrl::to('stories'),
            'importUrl' => AdminUrl::to(self::PATH),
        ], $user, 'Import report', self::PATH, $this->flash->pull($request->cookies));
    }

    /**
     * @param array<string, string> $params
     */
    public function download(array $params, Request $request): Response
    {
        $user = $this->open($request);

        if ($user instanceof Response) {
            return $user;
        }

        $report = $this->imports->report($user, $params['token'] ?? '');

        if ($report === null) {
            return $this->missing('That import report is not available.', self::PATH);
        }

        return new Response($this->imports->reportText($report), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="import-report.txt"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function showUndo(array $params, Request $request): Response
    {
        $user = $this->open($request);

        if ($user instanceof Response) {
            return $user;
        }

        $report = $this->imports->report($user, $params['token'] ?? '');

        if ($report === null || $report['undone']) {
            return $this->missing('That import cannot be undone.', self::PATH);
        }

        return $this->undoPage($request, $user, $report, [], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function undo(array $params, Request $request): Response
    {
        $user = $this->open($request);

        if ($user instanceof Response) {
            return $user;
        }

        $report = $this->imports->report($user, $params['token'] ?? '');

        if ($report === null || $report['undone']) {
            return $this->missing('That import cannot be undone.', self::PATH);
        }

        if (!$this->validToken($request, self::TOKEN)) {
            return $this->undoPage($request, $user, $report, ['form' => self::STALE_FORM], 400);
        }

        try {
            $removed = $this->imports->undo($user, $report['token'], $request->ip());
        } catch (ImportException $exception) {
            return $this->missing($exception->getMessage(), self::PATH);
        }

        $this->flash->add('success', $removed === 1 ? 'The import was undone: 1 story was deleted.' : 'The import was undone: ' . $removed . ' stories were deleted.');

        return Response::redirect(AdminUrl::to(self::PATH . '/report/' . $report['token']), 303);
    }

    private function open(Request $request): User|Response
    {
        $user = $this->writer($request, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!ImportSettings::enabled($this->config)) {
            return $this->missing('Importing stories is switched off on this site.', 'stories');
        }

        if (!$this->imports->allowed($user)) {
            return $this->missing('Importing stories is not open to this account.', 'stories');
        }

        return $user;
    }

    /**
     * @param array<string, string> $errors
     */
    private function uploadPage(Request $request, User $user, string $pseuds, array $errors, int $status): Response
    {
        return $this->view->render('import', [
            'errors' => $errors,
            'pseuds' => $pseuds,
            'zipAvailable' => UploadReader::zipAvailable(),
            'maxFiles' => UploadReader::MAX_FILES,
            'maxMegabytes' => (int) (UploadReader::MAX_FILE_BYTES / 1048576),
            'splitModes' => GeneralHtmlParser::SPLITS,
            'runs' => $this->imports->recentRuns($user),
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH),
            'reportBase' => AdminUrl::to(self::PATH . '/report'),
            'runBase' => AdminUrl::to(self::PATH . '/run'),
        ], $user, 'Import a story', self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @param array<string, string> $errors
     */
    private function previewPage(Request $request, User $user, string $token, array $errors, int $status, ?array $posted = null): Response
    {
        $preview = $this->imports->preview($user, $token);

        if ($preview === null) {
            return $this->missing('That import has expired. Upload the files again.', self::PATH);
        }

        return $this->view->render('import-preview', [
            'preview' => $preview,
            'errors' => $errors,
            'posted' => $posted ?? ($request->isPost() ? $request->post : []),
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH . '/preview/' . $token),
            'cancelUrl' => AdminUrl::to(self::PATH . '/preview/' . $token . '/cancel'),
        ], $user, 'Check the import', self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, string> $errors
     */
    private function undoPage(Request $request, User $user, array $report, array $errors, int $status): Response
    {
        return $this->view->render('import-undo', [
            'report' => $report,
            'errors' => $errors,
            'csrfToken' => Csrf::token(self::TOKEN),
            'action' => AdminUrl::to(self::PATH . '/report/' . $report['token'] . '/undo'),
            'backUrl' => AdminUrl::to(self::PATH . '/report/' . $report['token']),
        ], $user, 'Undo the import', self::PATH, $this->flash->pull($request->cookies), $status);
    }

    /**
     * @return array{include: list<int>, mode: array<int, string>, duplicate: array<int, string>, rating: array<string, int>, warning: array<string, int>, category: array<string, int>, series: array<string, string>, collection: array<string, string>, source_note: list<int>, meta: array<int, array<string, mixed>>, order: array<int, array<int, string>>}
     */
    private function choices(Request $request): array
    {
        $numbers = static fn (mixed $value): array => is_array($value) ? array_values(array_filter(array_map(static fn (mixed $item): int => is_string($item) && ctype_digit($item) ? (int) $item : -1, $value), static fn (int $item): bool => $item >= 0)) : [];

        $byWork = static function (mixed $value): array {
            $found = [];

            foreach (is_array($value) ? $value : [] as $key => $item) {
                if (is_scalar($item) && (is_int($key) || ctype_digit((string) $key))) {
                    $found[(int) $key] = (string) $item;
                }
            }

            return $found;
        };

        $byKey = static function (mixed $value): array {
            $found = [];

            foreach (is_array($value) ? $value : [] as $key => $item) {
                if (is_string($key) && preg_match('/^k[a-f0-9]{12}\z/', $key) === 1 && is_scalar($item)) {
                    $found[$key] = (string) $item;
                }
            }

            return $found;
        };

        $ids = static fn (array $map): array => array_map(static fn (string $item): int => ctype_digit($item) ? (int) $item : 0, $map);

        // What the author typed for a general file: only known fields, as text; the service checks each value again.
        $meta = [];

        foreach (is_array($request->post['meta'] ?? null) ? $request->post['meta'] : [] as $work => $fields) {
            if (!is_array($fields) || !(is_int($work) || ctype_digit((string) $work))) {
                continue;
            }

            foreach (['title', 'summary', 'notes', 'language', 'status', 'published', 'rating', 'fandom', 'character', 'relationship', 'tag'] as $name) {
                if (is_string($fields[$name] ?? null)) {
                    $meta[(int) $work][$name] = $fields[$name];
                }
            }

            foreach (['warning', 'genre'] as $name) {
                if (is_array($fields[$name] ?? null)) {
                    $meta[(int) $work][$name] = array_values(array_filter($fields[$name], 'is_string'));
                }
            }
        }

        $order = [];

        foreach (is_array($request->post['order'] ?? null) ? $request->post['order'] : [] as $work => $positions) {
            if (!is_array($positions) || !(is_int($work) || ctype_digit((string) $work))) {
                continue;
            }

            foreach ($positions as $position => $value) {
                if ((is_int($position) || ctype_digit((string) $position)) && is_string($value)) {
                    $order[(int) $work][(int) $position] = $value;
                }
            }
        }

        return [
            'meta' => $meta,
            'order' => $order,
            'include' => $numbers($request->post['include'] ?? null),
            'mode' => $byWork($request->post['mode'] ?? null),
            'duplicate' => $byWork($request->post['duplicate'] ?? null),
            'rating' => $ids($byKey($request->post['rating'] ?? null)),
            'warning' => $ids($byKey($request->post['warning'] ?? null)),
            'category' => $ids($byKey($request->post['category'] ?? null)),
            'series' => $byKey($request->post['series'] ?? null),
            'collection' => $byKey($request->post['collection'] ?? null),
            'source_note' => $numbers($request->post['source_note'] ?? null),
        ];
    }
}
