<?php

/**
 * The composition root: wires the core services together and dispatches a request.
 *
 * @package LumoraFiction
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core;

use LumoraFiction\Controllers\Admin\AdminRoutes;
use LumoraFiction\Controllers\Admin\AppearanceController;
use LumoraFiction\Controllers\Admin\ArchiveSettingsController;
use LumoraFiction\Controllers\Admin\ChapterController;
use LumoraFiction\Controllers\Admin\ContentEditorController;
use LumoraFiction\Controllers\Admin\EditorSettingsController;
use LumoraFiction\Controllers\Admin\ReviewController;
use LumoraFiction\Controllers\Admin\StoryController;
use LumoraFiction\Controllers\Admin\TaxonomyController;
use LumoraFiction\Core\Content\HtmlSanitizer;
use LumoraFiction\Core\Content\MarkdownParser;
use LumoraFiction\Controllers\Admin\AuthController;
use LumoraFiction\Controllers\Admin\DashboardController;
use LumoraFiction\Controllers\Admin\SystemInformationController;
use LumoraFiction\Core\Admin\AdminMenu;
use LumoraFiction\Core\Admin\AdminView;
use LumoraFiction\Core\Admin\ContentEditor;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Errors\ErrorHandler;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Hooks\Hooks;
use LumoraFiction\Core\Http\BasePath;
use LumoraFiction\Core\Http\FlashMessages;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Http\Router;
use LumoraFiction\Core\Mail\NativeMailer;
use LumoraFiction\Core\Security\ContentSecurityPolicy;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Core\Security\DatabaseSessionHandler;
use LumoraFiction\Core\Security\LoginThrottle;
use LumoraFiction\Core\Security\PasswordPolicy;
use LumoraFiction\Core\Security\SecurityHeaders;
use LumoraFiction\Core\Security\SessionManager;
use LumoraFiction\Core\Security\TokenService;
use LumoraFiction\Core\Theme\StaffNavigation;
use LumoraFiction\Core\Theme\ThemeCatalog;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Controllers\PublicController;
use LumoraFiction\Services\ArchiveSettings;
use LumoraFiction\Services\EditorSettings;
use LumoraFiction\Services\AuditLogService;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ChapterService;
use LumoraFiction\Services\ContentRenderer;
use LumoraFiction\Services\StoryPresenter;
use LumoraFiction\Services\StoryService;
use LumoraFiction\Services\TaxonomyService;
use LumoraFiction\Services\ThemeOptions;
use LumoraFiction\Services\ThemeSettings;
use LumoraFiction\Services\UserService;

/**
 * One Kernel is built per request and handed to the entry script, instead of services being
 * reached through global state. Later features add their services here.
 */
final class Kernel
{
    public function __construct(
        public readonly Configuration $config,
        public readonly Database $database,
        public readonly ErrorHandler $errors,
        public readonly HookManager $hooks,
        public readonly Router $router,
        public readonly SessionManager $sessions,
        public readonly UserService $users,
        public readonly AuthService $auth,
        public readonly AuditLogService $audit,
        public readonly ThemeRenderer $themes,
        private readonly bool $isHttps = false,
    ) {
    }

    public static function isInstalled(string $root): bool
    {
        return is_file($root . '/config/config.php');
    }

    /**
     * Builds the kernel for an installed site.
     *
     * @param array<string, mixed> $server
     * @throws Database\DatabaseConnectionException when the database cannot be reached
     */
    public static function boot(string $root, ErrorHandler $errors, array $server = []): self
    {
        $config = new Configuration($root . '/config/config.php');

        $errors->setDebug($config->get('debug', false) === true);

        $database = Database::connect(
            host: (string) $config->get('db_host'),
            database: (string) $config->get('db_name'),
            username: (string) $config->get('db_user'),
            password: (string) $config->get('db_password'),
            tablePrefix: (string) $config->get('table_prefix'),
            charset: (string) $config->get('db_charset', 'utf8mb4'),
            port: (int) $config->get('db_port', 3306),
        );

        $config->bindDatabase($database);

        // The shared instance is reused, not replaced, so anything registered before boot
        // (by bootstrap code or a test) is not silently dropped.
        $hooks = Hooks::instance();

        BasePath::set(BasePath::detect($server));

        $router = new Router();

        $config->registerDefaults(AuthService::DEFAULTS + ArchiveSettings::DEFAULTS + EditorSettings::DEFAULTS + TaxonomyService::DEFAULTS + ThemeSettings::DEFAULTS);

        $isHttps = SecurityHeaders::isHttps($server);
        $sessions = new SessionManager(
            $isHttps,
            SessionManager::DEFAULT_NAME,
            new DatabaseSessionHandler($database),
            (int) $config->setting('security.session_lifetime', 86400),
        );
        Csrf::useSessions($sessions);

        $passwords = new PasswordPolicy($config);
        $users = new UserService($database, $passwords);
        $siteUrl = rtrim((string) $config->get('site_url', ''), '/');

        $auth = new AuthService(
            $users,
            $sessions,
            new TokenService($database),
            new NativeMailer('noreply@' . (parse_url($siteUrl, PHP_URL_HOST) ?: 'localhost')),
            new LoginThrottle($database),
            $passwords,
            $config,
            $siteUrl,
        );

        $audit = new AuditLogService($database);

        // The template tags are plain functions so a theme can call them; they only forward to ThemeContext.
        require_once __DIR__ . '/../Helpers/template-tags.php';

        /** @var array{version: string} $version */
        $version = require $root . '/version.php';

        $themeCatalog = new ThemeCatalog($root . '/content/themes', __DIR__ . '/Theme/fallback');
        $themeOptions = new ThemeOptions($config, $hooks, $audit);
        $themes = new ThemeRenderer($themeCatalog, $config, $hooks, $themeOptions, $errors, $version['version']);

        $kernel = new self($config, $database, $errors, $hooks, $router, $sessions, $users, $auth, $audit, $themes, $isHttps);

        (new StaffNavigation($auth))->register($hooks);

        self::registerPublic($kernel, $themeOptions);
        self::registerAdmin($kernel, $root, $version['version'], $themeCatalog, $themeOptions);

        // Plugins and later features add their routes here.
        $hooks->doAction('routes_register', $router);
        $hooks->doAction('kernel_booted', $kernel);

        return $kernel;
    }

    /**
     * The public site's own addresses. Plugins add theirs through routes_register, after these.
     */
    private static function registerPublic(self $kernel, ThemeOptions $themeOptions): void
    {
        $public = new PublicController($kernel->themes, $themeOptions, __DIR__ . '/Theme/fallback/style.css');

        $kernel->router->get('/', $public->home(...), 'home');
        $kernel->router->get('/theme-options.css', $public->themeOptionsStylesheet(...), 'theme.options.css');
        $kernel->router->get('/theme-fallback.css', $public->fallbackStylesheet(...), 'theme.fallback.css');
    }

    /**
     * Wires the admin screens and adds their addresses to the router.
     */
    private static function registerAdmin(self $kernel, string $root, string $version, ThemeCatalog $themeCatalog, ThemeOptions $themeOptions): void
    {
        $flash = new FlashMessages($kernel->sessions);
        $view = new AdminView($root . '/admin/views', new AdminMenu($kernel->hooks), (string) $kernel->config->setting('site.name', 'Lumora Fiction'), $version);

        $editorSettings = new EditorSettings($kernel->config, $kernel->database, $kernel->audit);
        $renderer = new ContentRenderer(new MarkdownParser(), new HtmlSanitizer(), $kernel->hooks);
        $taxonomy = new TaxonomyService($kernel->database, $kernel->config, $kernel->audit);
        $stories = new StoryService($kernel->database, $kernel->config, $kernel->audit, $taxonomy, $kernel->hooks);
        $chapters = new ChapterService($kernel->database, $stories, $kernel->audit);
        $editor = new ContentEditor($view, $editorSettings, $version);
        $presenter = new StoryPresenter();

        AdminRoutes::register(
            $kernel->router,
            new AuthController($kernel->auth, $view, $flash),
            new DashboardController($kernel->auth, $view, $flash, $kernel->users, $kernel->audit, $kernel->config, $kernel->hooks, $stories, $root),
            new ArchiveSettingsController($kernel->auth, $view, $flash, new ArchiveSettings($kernel->config, $kernel->database, $kernel->audit)),
            new EditorSettingsController($kernel->auth, $view, $flash, $editorSettings),
            new ContentEditorController($kernel->auth, $renderer),
            new TaxonomyController($kernel->auth, $view, $flash, $taxonomy),
            new StoryController($kernel->auth, $view, $flash, $stories, $chapters, $taxonomy, $kernel->users, $kernel->themes, $presenter),
            new ChapterController($kernel->auth, $view, $flash, $stories, $chapters, $editor, $renderer, $kernel->users, $kernel->themes, $presenter),
            new ReviewController($kernel->auth, $view, $flash, $stories, $chapters, $kernel->users),
            new AppearanceController($kernel->auth, $view, $flash, $kernel->themes, new ThemeSettings($kernel->config, $themeCatalog, $kernel->audit), $themeOptions),
            new SystemInformationController($kernel->auth, $view, $flash, $root, $kernel->config, $kernel->database),
        );
    }

    /**
     * Matches the request to a route and runs its handler, or sends a 404 or 405 page. The handler
     * is given the route's parameters and the Request.
     */
    public function handle(string $method, string $uri, ?Request $request = null): void
    {
        $this->sendSecurityHeaders();

        $resolution = $this->router->resolve($method, BasePath::stripFrom($uri));

        if ($resolution->isFound() && $resolution->handler !== null) {
            $result = ($resolution->handler)($resolution->params, $request ?? Request::fromGlobals($method, $uri));

            // A handler may return a Response to be sent, or print its own output and return nothing.
            if ($result instanceof Response) {
                $result->send();
            }

            return;
        }

        $this->statusResponse($resolution->status, $resolution->allowedMethods)->send();
    }

    /**
     * The protective headers sent with every response, including the Content-Security-Policy.
     *
     * @return array<string, string> header name => value
     */
    public function responseHeaders(): array
    {
        $headers = SecurityHeaders::headers($this->isHttps, $this->config->setting('security.hsts', false) === true);

        // Themes and plugins widen the default same-origin policy through this filter, not by editing core.
        $directives = $this->hooks->applyFilters('csp_directives', ContentSecurityPolicy::defaultDirectives());

        $headers['Content-Security-Policy'] = (new ContentSecurityPolicy(is_array($directives) ? $directives : []))->header();

        return $headers;
    }

    /**
     * Sent before anything else so every response, including error pages, carries them.
     */
    private function sendSecurityHeaders(): void
    {
        SecurityHeaders::send($this->responseHeaders());
    }

    /**
     * The page for an address that matched nothing, or matched with another method. The active theme draws it;
     * if the theme layer itself fails, a bare page is sent so a visitor never sees an error for a missing page.
     *
     * @param list<string> $allowedMethods
     */
    private function statusResponse(int $status, array $allowedMethods): Response
    {
        $title = $status === 405 ? 'Method not allowed' : 'Page not found';
        $message = $status === 405
            ? 'That action is not available for this address.'
            : 'The page you are looking for does not exist.';

        try {
            $response = $this->themes->render('404', ['message' => $message], $status, ['title' => $title, 'noindex' => true]);
        } catch (\Throwable $exception) {
            $this->errors->report($exception);

            $response = Response::html(
                '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>' . $title . '</title></head><body><h1>' . $title . '</h1><p>' . $message . '</p></body></html>',
                $status,
            );
        }

        return $status === 405 ? $response->withHeader('Allow', implode(', ', $allowedMethods)) : $response;
    }
}
