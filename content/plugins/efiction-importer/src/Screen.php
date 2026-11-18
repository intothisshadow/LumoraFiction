<?php

/**
 * The eFiction importer screens: connect, preview and map, progress, report, and removal.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\EfictionImporter;

use LumoraFiction\Controllers\Admin\AdminController;
use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Admin\AdminView;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuditLogService;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\TaxonomyService;
use Throwable;

final class Screen extends AdminController
{
    public const PATH = 'maintenance/efiction-importer';

    /** The setting that switches the old-address redirects on. */
    public const REDIRECT_SETTING = 'efiction_importer.redirects';

    public const PHASES = [
        'ratings' => 'Ratings',
        'categories' => 'Fandoms',
        'characters' => 'Characters',
        'classes' => 'Genres, warnings and tags',
        'authors' => 'Accounts',
        'stories' => 'Stories and chapters',
        'series' => 'Series',
        'reviews' => 'Comments',
        'favorites' => 'Bookmarks',
        'news' => 'News posts',
        'pages' => 'Pages',
    ];

    /**
     * @param array{host: string, port: int, name: string, prefix: string} $own this site's own database connection
     */
    public function __construct(
        AuthService $auth,
        AdminView $view,
        FlashMessages $flash,
        private readonly Connection $connection,
        private readonly Database $database,
        private readonly Tracker $tracker,
        private readonly Importer $importer,
        private readonly TaxonomyService $taxonomy,
        private readonly AuditLogService $audit,
        private readonly Configuration $config,
        private readonly string $siteRoot,
        private readonly array $own,
        private readonly string $assetVersion = '',
    ) {
        parent::__construct($auth, $view, $flash);
    }

    // ---- connect ---------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $params
     */
    public function show(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        return $this->connectPage($request, $user, $this->connection->formValues());
    }

    /**
     * Fills in what the chosen folder's config.php names. The file is read as text and never run, and a password found there goes
     * into the session without being shown.
     *
     * @param array<string, string> $params
     */
    public function detect(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $values = $this->typedValues($request);

        if (!$this->validToken($request, 'efiction_connect')) {
            return $this->connectPage($request, $user, $values, ['form' => 'Your session expired, so nothing was read. Please try again.'], 400);
        }

        $folder = trim((string) ($request->input('pick') ?: $request->input('folder')));
        $real = $folder === '' || !FolderFinder::readable($folder) ? false : realpath($folder);
        $found = $real === false ? [] : ConfigReader::read($real);

        if ($real === false || $found === []) {
            return $this->connectPage($request, $user, $values, ['folder' => 'No settings could be read from that folder\'s config.php. Fill in the database details by hand below.']);
        }

        $values['folder'] = $real;

        foreach (['host', 'name', 'user', 'prefix'] as $field) {
            if (isset($found[$field])) {
                $values[$field] = $found[$field];
            }
        }

        if (isset($found['port'])) {
            $values['port'] = (string) $found['port'];
        }

        // The password is held for the run and never printed back; typing one in the form replaces it.
        $saved = $this->connection->get() ?? [];
        $this->connection->put($this->merged($values, isset($found['password']) ? $found['password'] : (string) ($saved['password'] ?? '')));
        $values['has_password'] = isset($found['password']) || ($saved['password'] ?? '') !== '';

        return $this->connectPage($request, $user, $values, [], 200, 'Read ' . implode(', ', array_map(static fn (string $field): string => match ($field) {
            'host' => 'the database host',
            'port' => 'the port',
            'name' => 'the database name',
            'user' => 'the user',
            'password' => 'a password (not shown)',
            default => 'the table prefix',
        }, array_keys($found))) . ' from config.php. Check them below; nothing has been connected yet.');
    }

    /**
     * @param array<string, string> $params
     */
    public function discover(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $values = $this->typedValues($request);

        if (!$this->validToken($request, 'efiction_connect')) {
            return $this->connectPage($request, $user, $values, ['form' => 'Your session expired, so nothing was searched. Please try again.'], 400);
        }

        return $this->connectPage($request, $user, $values, [], 200, '', FolderFinder::find($this->siteRoot), true);
    }

    /**
     * @param array<string, string> $params
     */
    public function connect(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $values = $this->typedValues($request);
        $values['prefix'] = trim((string) ($request->input('use_prefix') ?: $values['prefix']));

        if (!$this->validToken($request, 'efiction_connect')) {
            return $this->connectPage($request, $user, $values, ['form' => 'Your session expired, so nothing was connected. Please try again.'], 400);
        }

        $saved = $this->connection->get() ?? [];
        $typed = (string) $request->input('password');
        $password = $typed !== '' ? $typed : (string) ($saved['password'] ?? '');
        $port = ctype_digit($values['port']) && (int) $values['port'] > 0 && (int) $values['port'] <= 65535 ? (int) $values['port'] : 0;

        if ($port === 0) {
            return $this->connectPage($request, $user, $values, ['port' => 'The port is a number, usually 3306.'], 422);
        }

        try {
            $source = Source::connect($values['host'], $port, $values['name'], $values['user'], $password, $values['prefix'], $this->own);
        } catch (SourceException $exception) {
            return $this->connectPage($request, $user, $values, ['form' => $exception->getMessage()], 422);
        }

        $missing = array_values(array_filter(Source::REQUIRED, static fn (string $table): bool => !$source->hasTable($table)));

        if ($missing !== []) {
            // The details work, so they are kept and the right prefix can be chosen from the ones that fit.
            $this->connection->put($this->merged($values, $password, $port));

            return $this->connectPage($request, $user, $values + ['has_password' => $password !== ''], [
                'prefix' => 'There are no eFiction tables with the prefix "' . $values['prefix'] . '" in that database.' . ($source->prefixes() === [] ? ' None were found under another prefix either.' : ' Choose one of the prefixes that do have them, or type another.'),
            ], 422, '', [], false, $source->prefixes(), Source::databases($values['host'], $port, $values['user'], $password));
        }

        $this->connection->put($this->merged($values, $password, $port));
        $this->audit->record($user->id, 'plugin.efiction_importer.connect', 'plugin', 'efiction-importer', ['prefix' => $values['prefix']], $request->ip());

        return Response::redirect(AdminUrl::to(self::PATH . '/preview'), 303);
    }

    /**
     * Drops the held connection, password included.
     *
     * @param array<string, string> $params
     */
    public function disconnect(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if ($this->validToken($request, 'efiction_forget')) {
            $this->connection->forget();
            $this->flash->add('success', 'The connection was dropped, and the password with it.');
        }

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    // ---- preview ---------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $params
     */
    public function preview(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $opened = $this->open();

        if ($opened instanceof Response) {
            return $opened;
        }

        [$source, $conn] = $opened;
        $this->tracker->ensureTables();
        $settings = $source->settings();
        $text = $this->textFor($conn, $settings);
        $survey = new Survey($source, $this->database, $this->taxonomy, $this->tracker);

        try {
            $facts = $survey->facts($text);
            $mapping = $survey->mapping();
        } catch (SourceException $exception) {
            $this->flash->add('error', $exception->getMessage());

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        $this->audit->record($user->id, 'plugin.efiction_importer.preview', 'plugin', 'efiction-importer', ['stories' => $facts['stories'], 'authors' => $facts['authors']], $request->ip());

        return $this->render($request, $user, 'preview', 'eFiction importer: preview', [
            'facts' => $facts,
            'mapping' => $mapping,
            'pages' => $survey->pages(),
            'fandoms' => $this->taxonomy->list(TermType::Fandom, [], 300),
            'ratings' => $this->taxonomy->list(TermType::Rating, [], 100),
            'storiesFolder' => $this->storiesFolder($conn, $settings),
            'kinds' => Mapping::KINDS,
            'startToken' => Csrf::token('efiction_start'),
            'startAction' => AdminUrl::to(self::PATH . '/start'),
            'forgetToken' => Csrf::token('efiction_forget'),
            'forgetAction' => AdminUrl::to(self::PATH . '/forget'),
            'backupUrl' => AdminUrl::to('maintenance/backups'),
            'maintenanceUrl' => AdminUrl::to('maintenance/mode'),
            'homeUrl' => AdminUrl::to(self::PATH),
        ]);
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

        if (!$this->validToken($request, 'efiction_start')) {
            $this->flash->add('error', 'Your session expired, so nothing was imported. Please check the choices and try again.');

            return Response::redirect(AdminUrl::to(self::PATH . '/preview'), 303);
        }

        $opened = $this->open();

        if ($opened instanceof Response) {
            return $opened;
        }

        [$source, $conn] = $opened;
        $survey = new Survey($source, $this->database, $this->taxonomy, $this->tracker);

        if (!$request->checked('confirm')) {
            $this->flash->add('error', 'Tick the box to say you have taken a backup, so nothing is imported by accident.');

            return Response::redirect(AdminUrl::to(self::PATH . '/preview'), 303);
        }

        $input = [];

        foreach (array_keys($request->post) as $name) {
            if (is_string($name) && preg_match('/^(rating|category|classtype)_\d+\z|^characters\z/', $name) === 1) {
                $input[$name] = $request->input($name);
            }
        }

        try {
            $mapping = Mapping::fromInput($survey->mapping(), $input, $this->taxonomy);
            $offered = array_column($survey->pages(), 'id');
            $settings = $source->settings();
        } catch (SourceException $exception) {
            $this->flash->add('error', $exception->getMessage());

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        $options = [
            'reviews' => $request->checked('opt_reviews'),
            'guest_reviews' => $request->checked('opt_guest_reviews'),
            'series' => $request->checked('opt_series'),
            'favorites' => $request->checked('opt_favorites'),
            'news' => $request->checked('opt_news'),
            'bio' => $request->checked('opt_bio'),
            'pages' => array_values(array_filter(array_map('intval', $request->inputList('pages')), static fn (int $id): bool => in_array($id, $offered, true))),
            'unvalidated' => $request->input('unvalidated') === 'approval' ? 'approval' : 'draft',
            'language' => (string) ($settings['language'] ?? 'en'),
        ];

        $runId = $this->importer->begin($user, $options, $mapping, $request->ip());

        return Response::redirect(AdminUrl::to(self::PATH . '/progress/' . $runId), 303);
    }

    // ---- progress and report ---------------------------------------------------------------------------

    /**
     * @param array<string, string> $params
     */
    public function progress(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $run = $this->tracker->run((int) ($params['id'] ?? 0));

        if ($run === null) {
            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        if ($run['state'] !== 'running') {
            return Response::redirect(AdminUrl::to(self::PATH . '/report/' . $run['id']), 303);
        }

        $phase = (string) ($run['job']['phase'] ?? '');
        $phases = array_values(array_filter((array) ($run['job']['phases'] ?? []), 'is_string'));
        $position = array_search($phase, $phases, true);

        return $this->render($request, $user, 'progress', 'eFiction importer: working', [
            'run' => $run,
            'report' => Report::fromArray($run['report']),
            'phaseLabel' => self::PHASES[$phase] ?? '',
            'phaseNumber' => $position === false ? count($phases) : $position + 1,
            'phaseTotal' => count($phases),
            'paused' => $request->queryValue('paused') === '1',
            'connected' => $this->connection->get() !== null,
            'stepToken' => Csrf::token('efiction_step'),
            'stepAction' => AdminUrl::to(self::PATH . '/step/' . $run['id']),
            'stopToken' => Csrf::token('efiction_stop'),
            'stopAction' => AdminUrl::to(self::PATH . '/stop/' . $run['id']),
            'connectUrl' => AdminUrl::to(self::PATH),
            'assetUrl' => AdminUrl::asset('js/import-progress.js', $this->assetVersion),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function step(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $runId = (int) ($params['id'] ?? 0);
        $back = AdminUrl::to(self::PATH . '/progress/' . $runId);

        if (!$this->validToken($request, 'efiction_step')) {
            $this->flash->add('error', 'Your session expired. Press Carry on again.');

            return Response::redirect($back . '?paused=1', 303);
        }

        $opened = $this->open();

        if ($opened instanceof Response) {
            // The password lived in the session; once that is gone the run waits for it to be entered again.
            return $opened;
        }

        [$source, $conn] = $opened;

        try {
            $result = $this->importer->step($user, $source, $this->textFor($conn, $source->settings()), $runId);
        } catch (Throwable $exception) {
            $this->flash->add('error', 'The import stopped on an error: ' . ($exception instanceof SourceException ? $exception->getMessage() : 'see the error log for the details.') . ' What was done is kept. Press Carry on to try again.');

            return Response::redirect($back . '?paused=1', 303);
        }

        return Response::redirect($result['done'] ? AdminUrl::to(self::PATH . '/report/' . $runId) : $back, 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function stop(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $runId = (int) ($params['id'] ?? 0);

        if ($this->validToken($request, 'efiction_stop')) {
            $this->importer->cancel($user, $runId, $request->ip());
            $this->flash->add('success', 'The import was stopped. What was already brought across stays, and running it again carries on from there.');
        }

        return Response::redirect(AdminUrl::to(self::PATH . '/report/' . $runId), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function report(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        $run = $this->tracker->run((int) ($params['id'] ?? 0));

        if ($run === null) {
            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        $report = Report::fromArray($run['report']);
        $format = (string) $request->queryValue('download');

        if ($format === 'txt') {
            return Response::download($report->text(), 'efiction-import-report-' . $run['id'] . '.txt', 'text/plain; charset=utf-8');
        }

        if ($format === 'csv') {
            return Response::download($report->csv(), 'efiction-import-report-' . $run['id'] . '.csv', 'text/csv; charset=utf-8');
        }

        return $this->render($request, $user, 'report', 'eFiction importer: report', [
            'run' => $run,
            'report' => $report,
            'homeUrl' => AdminUrl::to(self::PATH),
            'textUrl' => AdminUrl::to(self::PATH . '/report/' . $run['id']) . '?download=txt',
            'csvUrl' => AdminUrl::to(self::PATH . '/report/' . $run['id']) . '?download=csv',
            'resumeUrl' => AdminUrl::to(self::PATH . '/progress/' . $run['id']),
            'usersUrl' => AdminUrl::to('users'),
        ]);
    }

    // ---- removal and redirects -------------------------------------------------------------------------

    /**
     * @param array<string, string> $params
     */
    public function remove(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, 'efiction_remove')) {
            $this->flash->add('error', 'Your session expired, so nothing was removed. Please try again.');
        } elseif (!$request->checked('confirm')) {
            $this->flash->add('error', 'Tick the box to confirm, so nothing is removed by accident.');
        } else {
            $removed = $this->importer->removeAll($user, $request->ip());
            $this->flash->add('success', sprintf(
                'Removed %s stories (with their chapters), %s comments, %s bookmarks, %s news posts, %s pages, %s classification terms and %s accounts.%s',
                number_format($removed['story']),
                number_format($removed['comment']),
                number_format($removed['bookmark']),
                number_format($removed['news']),
                number_format($removed['page']),
                number_format($removed['term']),
                number_format($removed['user']),
                $removed['kept'] > 0 ? ' ' . number_format($removed['kept']) . ' were kept because something made here since the import still uses them.' : '',
            ));
        }

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    /**
     * @param array<string, string> $params
     */
    public function redirects(array $params, Request $request): Response
    {
        $user = $this->guard($request, Capability::ManageSite, self::PATH);

        if ($user instanceof Response) {
            return $user;
        }

        if (!$this->validToken($request, 'efiction_redirects')) {
            $this->flash->add('error', 'Your session expired, so nothing was changed. Please try again.');
        } else {
            $on = $request->checked('redirects');
            $this->config->setSetting(self::REDIRECT_SETTING, $on);
            $this->audit->record($user->id, 'plugin.efiction_importer.redirects', 'plugin', 'efiction-importer', ['on' => $on], $request->ip());
            $this->flash->add('success', $on ? 'Old eFiction addresses now lead to the imported stories, authors and pages.' : 'The old eFiction addresses no longer redirect.');
        }

        return Response::redirect(AdminUrl::to(self::PATH), 303);
    }

    // ---- helpers ---------------------------------------------------------------------------------------

    /**
     * @return array{0: Source, 1: array{host: string, port: int, name: string, user: string, password: string, prefix: string, folder: string, stories: string}}|Response
     */
    private function open(): array|Response
    {
        $conn = $this->connection->get();

        if ($conn === null) {
            $this->flash->add('error', 'Connect to the eFiction database first. The details are kept only for the time you are signed in, so they have to be entered again after that.');

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }

        try {
            return [Source::connect($conn['host'], $conn['port'], $conn['name'], $conn['user'], $conn['password'], $conn['prefix'], $this->own), $conn];
        } catch (SourceException $exception) {
            $this->flash->add('error', $exception->getMessage());

            return Response::redirect(AdminUrl::to(self::PATH), 303);
        }
    }

    /**
     * @param array{stories: string, folder: string} $conn
     * @param array<string, string> $settings
     */
    private function textFor(array $conn, array $settings): ChapterText
    {
        return new ChapterText($this->storiesFolder($conn, $settings), ($settings['store'] ?? 'files') === 'db' ? 'db' : 'files', $this->charset($conn['folder'], (string) ($settings['language'] ?? 'en')));
    }

    /**
     * The folder the administrator named, else eFiction's own setting (a name under its own folder), else nothing.
     *
     * @param array{stories: string, folder: string} $conn
     * @param array<string, string> $settings
     */
    private function storiesFolder(array $conn, array $settings): string
    {
        if ($conn['stories'] !== '') {
            return $conn['stories'];
        }

        $name = trim((string) ($settings['storiespath'] ?? ''), '/\\');

        return $conn['folder'] !== '' ? rtrim($conn['folder'], '/\\') . '/' . ($name !== '' && preg_match('/^[A-Za-z0-9_.-]+\z/', $name) === 1 ? $name : 'stories') : '';
    }

    /**
     * What the site's language file says its character set is, when it says so in plain text.
     */
    private function charset(string $folder, string $language): string
    {
        if ($folder === '' || preg_match('/^[A-Za-z0-9_-]{1,12}\z/', $language) !== 1) {
            return 'Windows-1252';
        }

        $file = rtrim($folder, '/\\') . '/languages/' . $language . '.php';

        if (!FolderFinder::readable($file) || !is_file($file) || is_link($file)) {
            return 'Windows-1252';
        }

        $head = (string) file_get_contents($file, false, null, 0, 20000);

        return preg_match('/charset\s*=\s*["\']?([A-Za-z0-9_-]+)/i', $head, $match) === 1 ? $match[1] : 'Windows-1252';
    }

    /**
     * @return array{host: string, port: string, name: string, user: string, prefix: string, folder: string, stories: string, has_password: bool}
     */
    private function typedValues(Request $request): array
    {
        $saved = $this->connection->get();

        return [
            'host' => trim((string) $request->input('host')),
            'port' => trim((string) ($request->input('port') ?? '3306')),
            'name' => trim((string) $request->input('name')),
            'user' => trim((string) $request->input('user')),
            'prefix' => trim((string) $request->input('prefix')),
            'folder' => trim((string) $request->input('folder')),
            'stories' => trim((string) $request->input('stories')),
            'has_password' => (string) $request->input('password') !== '' || ($saved['password'] ?? '') !== '',
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, int|string>
     */
    private function merged(array $values, string $password, ?int $port = null): array
    {
        return [
            'host' => (string) $values['host'],
            'port' => $port ?? (ctype_digit((string) $values['port']) ? (int) $values['port'] : 3306),
            'name' => (string) $values['name'],
            'user' => (string) $values['user'],
            'password' => $password,
            'prefix' => (string) $values['prefix'],
            'folder' => (string) $values['folder'],
            'stories' => (string) $values['stories'],
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     * @param list<string> $folders
     * @param list<string> $prefixes
     * @param list<string> $databases the databases the account may see, when the server lets it list them
     */
    private function connectPage(Request $request, User $user, array $values, array $errors = [], int $status = 200, string $notice = '', array $folders = [], bool $searched = false, array $prefixes = [], array $databases = []): Response
    {
        $this->tracker->ensureTables();
        $latest = $this->tracker->latestRun();

        return $this->render($request, $user, 'connect', 'eFiction importer', [
            'values' => $values,
            'errors' => $errors,
            'notice' => $notice,
            'folders' => $folders,
            'searched' => $searched,
            'prefixes' => $prefixes,
            'databases' => $databases,
            'made' => $this->tracker->counts(),
            'latest' => $latest,
            'redirectsOn' => $this->config->setting(self::REDIRECT_SETTING, false) === true,
            'connectToken' => Csrf::token('efiction_connect'),
            'removeToken' => Csrf::token('efiction_remove'),
            'redirectToken' => Csrf::token('efiction_redirects'),
            'forgetToken' => Csrf::token('efiction_forget'),
            'connectAction' => AdminUrl::to(self::PATH . '/connect'),
            'detectAction' => AdminUrl::to(self::PATH . '/detect'),
            'discoverAction' => AdminUrl::to(self::PATH . '/discover'),
            'removeAction' => AdminUrl::to(self::PATH . '/remove'),
            'redirectAction' => AdminUrl::to(self::PATH . '/redirects'),
            'forgetAction' => AdminUrl::to(self::PATH . '/forget'),
            'reportUrl' => $latest === null ? '' : AdminUrl::to(self::PATH . '/report/' . $latest['id']),
            'resumeUrl' => $latest !== null && $latest['state'] === 'running' ? AdminUrl::to(self::PATH . '/progress/' . $latest['id']) : '',
        ], $status);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(Request $request, User $user, string $view, string $title, array $data, int $status = 200): Response
    {
        $content = (static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();

            try {
                require $__file;
            } catch (Throwable $exception) {
                ob_end_clean();

                throw $exception;
            }

            return (string) ob_get_clean();
        })(dirname(__DIR__) . '/views/' . $view . '.php', $data);

        return $this->view->renderContent($content, $user, $title, self::PATH, $this->flash->pull($request->cookies), $status);
    }
}
