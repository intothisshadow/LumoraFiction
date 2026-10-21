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
use LumoraFiction\Controllers\Admin\PluginsController;
use LumoraFiction\Controllers\Admin\ReviewController;
use LumoraFiction\Controllers\Admin\SecurityEventsController;
use LumoraFiction\Controllers\Admin\SecuritySettingsController;
use LumoraFiction\Controllers\Admin\SpamSettingsController;
use LumoraFiction\Controllers\Admin\StoryController;
use LumoraFiction\Controllers\Admin\TaxonomyController;
use LumoraFiction\Controllers\Admin\UsersController;
use LumoraFiction\Services\UserAdminService;
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
use LumoraFiction\Core\Http\TrustedProxies;
use LumoraFiction\Core\Mail\NativeMailer;
use LumoraFiction\Core\Plugin\PluginCatalog;
use LumoraFiction\Core\Plugin\PluginLoader;
use LumoraFiction\Core\Plugin\PluginNotices;
use LumoraFiction\Core\Security\ContentSecurityPolicy;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Core\Security\DatabaseSessionHandler;
use LumoraFiction\Core\Security\FormShield;
use LumoraFiction\Core\Security\LoginThrottle;
use LumoraFiction\Core\Security\PasswordPolicy;
use LumoraFiction\Core\Security\RegistrationNotices;
use LumoraFiction\Core\Security\RegistrationThrottle;
use LumoraFiction\Core\Security\SecurityHeaders;
use LumoraFiction\Core\Security\SessionManager;
use LumoraFiction\Core\Security\TokenService;
use LumoraFiction\Core\Theme\FormProtectionFields;
use LumoraFiction\Core\Theme\StaffNavigation;
use LumoraFiction\Core\Theme\ThemeCatalog;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Controllers\AccountController;
use LumoraFiction\Controllers\ArchiveController;
use LumoraFiction\Core\Theme\AccountNavigation;
use LumoraFiction\Core\Theme\ReaderHead;
use LumoraFiction\Controllers\PublicController;
use LumoraFiction\Controllers\ReaderController;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Models\TermType;
use LumoraFiction\Services\AkismetClient;
use LumoraFiction\Services\ArchiveSettings;
use LumoraFiction\Services\BookmarkPresenter;
use LumoraFiction\Services\FormGuard;
use LumoraFiction\Services\RegistrationGuard;
use LumoraFiction\Services\SecurityEventLog;
use LumoraFiction\Services\SecuritySettings;
use LumoraFiction\Services\SpamSettings;
use LumoraFiction\Services\BookmarkService;
use LumoraFiction\Services\PluginManager;
use LumoraFiction\Services\PublicArchive;
use LumoraFiction\Services\ReaderPreferences;
use LumoraFiction\Services\ReadingProgressService;
use LumoraFiction\Services\SearchPresenter;
use LumoraFiction\Services\SearchQueryParser;
use LumoraFiction\Services\EditorSettings;
use LumoraFiction\Services\AuditLogService;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ChapterService;
use LumoraFiction\Services\ContentRenderer;
use LumoraFiction\Core\Media\CoverImageStore;
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
        public readonly ?TrustedProxies $proxies = null,
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

        $config->registerDefaults(AuthService::DEFAULTS + ArchiveSettings::DEFAULTS + EditorSettings::DEFAULTS + TaxonomyService::DEFAULTS + ThemeSettings::DEFAULTS + PluginManager::DEFAULTS + SpamSettings::DEFAULTS + SecurityEventLog::DEFAULTS + SecuritySettings::DEFAULTS);

        // Before anything reads the address or HTTPS, so the sessions, headers and rate limits all see what a trusted proxy reported.
        $proxies = TrustedProxies::fromSetting($config->setting('security.trusted_proxies', ''));
        $server = $proxies->resolve($server);

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

        $securityEvents = new SecurityEventLog($database, $config);
        $audit = new AuditLogService($database);
        $securitySettings = new SecuritySettings($config, $database, $audit);
        $lockout = $securitySettings->current();

        $auth = new AuthService(
            $users,
            $sessions,
            new TokenService($database),
            new NativeMailer('noreply@' . (parse_url($siteUrl, PHP_URL_HOST) ?: 'localhost')),
            new LoginThrottle($database, $lockout['lockout_attempts'], $lockout['lockout_minutes'] * 60, $lockout['lockout_minutes'] * 60, $lockout['lockout_progressive']),
            $passwords,
            $config,
            $siteUrl,
            events: $securityEvents,
        );

        $spamSettings = new SpamSettings($config, $database, $audit, $securityEvents);
        $registrationThrottle = new RegistrationThrottle($database, $config);
        $akismet = new AkismetClient($siteUrl);
        $formShield = new FormShield((string) $config->get('secret_key', ''), $spamSettings->current()['min_seconds']);
        $formGuard = new FormGuard($formShield, $securityEvents);
        $registrationGuard = new RegistrationGuard($registrationThrottle, $formGuard, $securityEvents, $spamSettings, $akismet, $hooks, $audit);
        (new FormProtectionFields($formShield))->register($hooks);
        (new RegistrationNotices($registrationThrottle))->register($hooks);

        // The template tags are plain functions so a theme can call them; they only forward to ThemeContext.
        require_once __DIR__ . '/../Helpers/template-tags.php';

        /** @var array{version: string} $version */
        $version = require $root . '/version.php';

        $themeCatalog = new ThemeCatalog($root . '/content/themes', __DIR__ . '/Theme/fallback');
        $themeOptions = new ThemeOptions($config, $hooks, $audit);
        $themes = new ThemeRenderer($themeCatalog, $config, $hooks, $themeOptions, $errors, $version['version']);

        $kernel = new self($config, $database, $errors, $hooks, $router, $sessions, $users, $auth, $audit, $themes, $isHttps, $proxies);

        (new StaffNavigation($auth))->register($hooks);

        // The public pages and the admin screens work on the same services, so each is built once.
        $taxonomy = new TaxonomyService($kernel->database, $kernel->config, $kernel->audit);
        $covers = new CoverImageStore($root . '/content/uploads/covers');
        $stories = new StoryService($kernel->database, $kernel->config, $kernel->audit, $taxonomy, $kernel->hooks, $covers);
        $chapters = new ChapterService($kernel->database, $stories, $kernel->audit);
        $renderer = new ContentRenderer(new MarkdownParser(), new HtmlSanitizer(), $kernel->hooks);
        $presenter = new StoryPresenter($covers);
        $flash = new FlashMessages($sessions);
        $editorSettings = new EditorSettings($config, $database, $audit);

        $pluginCatalog = new PluginCatalog($root . '/content/plugins');
        $plugins = new PluginManager($config, $pluginCatalog, new PluginLoader($kernel, $pluginCatalog, $version['version'], $root), $audit, $hooks, $errors, $version['version']);
        (new PluginNotices($plugins))->register($hooks);

        self::registerPublic($kernel, $themeOptions, $taxonomy, $stories, $chapters, $renderer, $presenter, $flash, $editorSettings, $registrationGuard, $formGuard);
        self::registerAdmin($kernel, $root, $version['version'], $themeCatalog, $themeOptions, $taxonomy, $stories, $chapters, $renderer, $presenter, $flash, $editorSettings, $plugins, $spamSettings, $registrationThrottle, $akismet, $securityEvents, $securitySettings);

        // Plugins load after the core routes are registered, so none of them can take over a core address.
        $plugins->loadActive();
        $hooks->doAction('plugins_loaded', $kernel);

        // Plugins and later features add their routes here.
        $hooks->doAction('routes_register', $router);
        $hooks->doAction('kernel_booted', $kernel);

        return $kernel;
    }

    /**
     * The public site's own addresses. Plugins add theirs through routes_register, after these.
     */
    private static function registerPublic(self $kernel, ThemeOptions $themeOptions, TaxonomyService $taxonomy, StoryService $stories, ChapterService $chapters, ContentRenderer $renderer, StoryPresenter $presenter, FlashMessages $flash, EditorSettings $editorSettings, RegistrationGuard $registrationGuard, FormGuard $formGuard): void
    {
        $readerPreferences = new ReaderPreferences($kernel->database);
        $bookmarks = new BookmarkService($kernel->database, $stories);
        $bookmarkPresenter = new BookmarkPresenter($bookmarks);
        $account = new AccountController($kernel->themes, $kernel->auth, $kernel->users, $editorSettings, $flash, $kernel->config, $kernel->audit, $readerPreferences, $registrationGuard, $formGuard);
        $reader = new ReaderController($kernel->themes, $kernel->auth, $bookmarks, $bookmarkPresenter, $stories, $presenter, $kernel->users, $flash);
        (new AccountNavigation($kernel->auth, $kernel->config))->register($kernel->hooks);
        (new ReaderHead($kernel->auth, $readerPreferences))->register($kernel->hooks);

        $public = new PublicController($kernel->themes, $themeOptions, __DIR__ . '/Theme/fallback/style.css');
        $publicArchive = new PublicArchive($kernel->database, $stories);
        $searchParser = new SearchQueryParser($taxonomy);
        $archive = new ArchiveController(
            $kernel->themes,
            $stories,
            $chapters,
            $taxonomy,
            $kernel->users,
            $publicArchive,
            $presenter,
            $renderer,
            $kernel->auth,
            $kernel->config,
            $searchParser,
            new SearchPresenter($taxonomy, $searchParser, $publicArchive),
            $bookmarkPresenter,
            new ReadingProgressService($kernel->database, $stories),
            $flash,
        );

        $kernel->router->get('/', $archive->home(...), 'home');
        $kernel->router->get(PublicUrl::SEARCH, $archive->search(...), 'search');
        $kernel->router->get('/theme-options.css', $public->themeOptionsStylesheet(...), 'theme.options.css');
        $kernel->router->get('/theme-fallback.css', $public->fallbackStylesheet(...), 'theme.fallback.css');

        $kernel->router->get(PublicUrl::SIGN_IN, $account->showSignIn(...), 'account.sign_in');
        $kernel->router->post(PublicUrl::SIGN_IN, $account->signIn(...), 'account.sign_in.submit');
        $kernel->router->post(PublicUrl::SIGN_OUT, $account->signOut(...), 'account.sign_out');
        $kernel->router->get(PublicUrl::REGISTER, $account->showRegister(...), 'account.register');
        $kernel->router->post(PublicUrl::REGISTER, $account->register(...), 'account.register.submit');
        $kernel->router->get(PublicUrl::VERIFY, $account->verify(...), 'account.verify');
        $kernel->router->get(PublicUrl::RESEND, $account->showResend(...), 'account.resend');
        $kernel->router->post(PublicUrl::RESEND, $account->resend(...), 'account.resend.submit');
        $kernel->router->get(PublicUrl::FORGOT, $account->showForgot(...), 'account.forgot');
        $kernel->router->post(PublicUrl::FORGOT, $account->forgot(...), 'account.forgot.submit');
        $kernel->router->get(PublicUrl::RESET, $account->showReset(...), 'account.reset');
        $kernel->router->post(PublicUrl::RESET, $account->reset(...), 'account.reset.submit');
        $kernel->router->get(PublicUrl::PROFILE, $account->profile(...), 'account.profile');
        $kernel->router->post(PublicUrl::PROFILE . '/name', $account->updateName(...), 'account.profile.name');
        $kernel->router->post(PublicUrl::PROFILE . '/email', $account->updateEmail(...), 'account.profile.email');
        $kernel->router->post(PublicUrl::PROFILE . '/password', $account->updatePassword(...), 'account.profile.password');
        $kernel->router->post(PublicUrl::PROFILE . '/editor', $account->updateEditor(...), 'account.profile.editor');
        $kernel->router->post(PublicUrl::READING, $account->updateReading(...), 'account.profile.reading');

        $kernel->router->get(PublicUrl::BOOKMARKS, $reader->list(...), 'account.bookmarks');
        $kernel->router->post(PublicUrl::BOOKMARK_ADD, $reader->add(...), 'account.bookmarks.add');
        $kernel->router->post(PublicUrl::BOOKMARK_REMOVE, $reader->remove(...), 'account.bookmarks.remove');

        $kernel->router->get(PublicUrl::STORY, $archive->story(...), 'story');
        $kernel->router->get(PublicUrl::CHAPTER, $archive->chapter(...), 'chapter');
        $kernel->router->get(PublicUrl::AUTHOR, $archive->author(...), 'author');

        // One route per kind of term, so an address under any other first segment is never read as a term.
        foreach (TermType::cases() as $type) {
            $kernel->router->get(PublicUrl::termPattern($type), static fn (array $params, Request $request): Response => $archive->term($type, $params, $request), 'term.' . $type->value);
        }
    }

    /**
     * Wires the admin screens and adds their addresses to the router.
     */
    private static function registerAdmin(self $kernel, string $root, string $version, ThemeCatalog $themeCatalog, ThemeOptions $themeOptions, TaxonomyService $taxonomy, StoryService $stories, ChapterService $chapters, ContentRenderer $renderer, StoryPresenter $presenter, FlashMessages $flash, EditorSettings $editorSettings, PluginManager $plugins, SpamSettings $spamSettings, RegistrationThrottle $registrationThrottle, AkismetClient $akismet, SecurityEventLog $securityEvents, SecuritySettings $securitySettings): void
    {
        $view = new AdminView($root . '/admin/views', new AdminMenu($kernel->hooks), (string) $kernel->config->setting('site.name', 'Lumora Fiction'), $version);

        $editor = new ContentEditor($view, $editorSettings, $version);

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
            new UsersController($kernel->auth, $view, $flash, new UserAdminService($kernel->database, $kernel->users, $stories, $kernel->audit, $kernel->config, $kernel->hooks), $kernel->users, $stories, $kernel->config),
            new AppearanceController($kernel->auth, $view, $flash, $kernel->themes, new ThemeSettings($kernel->config, $themeCatalog, $kernel->audit), $themeOptions),
            new SystemInformationController($kernel->auth, $view, $flash, $root, $kernel->config, $kernel->database),
            new PluginsController($kernel->auth, $view, $flash, $plugins),
            new SpamSettingsController($kernel->auth, $view, $flash, $spamSettings, $registrationThrottle, $akismet, $kernel->audit),
            new SecurityEventsController($kernel->auth, $view, $flash, $securityEvents),
            new SecuritySettingsController($kernel->auth, $view, $flash, $securitySettings),
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
            $result = ($resolution->handler)($resolution->params, $request ?? Request::fromGlobals($method, $uri, $this->proxies));

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
            : ThemeRenderer::NOT_FOUND_MESSAGE;

        try {
            $response = $status === 405
                ? $this->themes->render('404', ['message' => $message], $status, ['title' => $title, 'noindex' => true])
                : $this->themes->notFound();
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
