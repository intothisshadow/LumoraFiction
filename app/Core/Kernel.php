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
use LumoraFiction\Controllers\Admin\BackupsController;
use LumoraFiction\Controllers\Admin\MaintenanceModeController;
use LumoraFiction\Controllers\Admin\UpdatesController;
use LumoraFiction\Core\Database\Migrator;
use LumoraFiction\Core\Http\MaintenanceFlag;
use LumoraFiction\Core\Update\CompatibilityChecker;
use LumoraFiction\Core\Update\ReleaseNotes;
use LumoraFiction\Core\Update\HttpClient;
use LumoraFiction\Core\Update\PackageInspector;
use LumoraFiction\Core\Update\ReleaseFeed;
use LumoraFiction\Core\Update\UpdateStorage;
use LumoraFiction\Services\BackupService;
use LumoraFiction\Services\MaintenanceMode;
use LumoraFiction\Services\MaintenanceNotice;
use LumoraFiction\Services\UpdateChecker;
use LumoraFiction\Services\UpdateService;
use Throwable;
use LumoraFiction\Controllers\Admin\AlertsController;
use LumoraFiction\Controllers\Admin\NewsController as AdminNewsController;
use LumoraFiction\Controllers\Admin\NewsSettingsController;
use LumoraFiction\Controllers\Admin\FrontPageSettingsController;
use LumoraFiction\Services\FrontPageSettings;
use LumoraFiction\Controllers\DownloadController;
use LumoraFiction\Controllers\FeedController;
use LumoraFiction\Controllers\Admin\DownloadSettingsController;
use LumoraFiction\Controllers\Admin\CollectionController;
use LumoraFiction\Controllers\Admin\ExportController;
use LumoraFiction\Controllers\Admin\FeedSettingsController;
use LumoraFiction\Controllers\Admin\GroupingSettingsController;
use LumoraFiction\Controllers\Admin\SeriesController;
use LumoraFiction\Core\Theme\FeedLinks;
use LumoraFiction\Core\Theme\SearchHead;
use LumoraFiction\Controllers\SearchEngineController;
use LumoraFiction\Controllers\Admin\RedirectsController;
use LumoraFiction\Controllers\Admin\SearchSettingsController;
use LumoraFiction\Controllers\Admin\SettingsTransferController;
use LumoraFiction\Services\RedirectService;
use LumoraFiction\Services\SearchSettings;
use LumoraFiction\Services\SettingsTransfer;
use LumoraFiction\Services\SitemapService;
use LumoraFiction\Services\FeedService;
use LumoraFiction\Services\FeedSettings;
use LumoraFiction\Models\Capability;
use LumoraFiction\Services\DownloadAccess;
use LumoraFiction\Services\PendingMigrations;
use LumoraFiction\Services\DownloadCounter;
use LumoraFiction\Services\DownloadPresenter;
use LumoraFiction\Services\DownloadSettings;
use LumoraFiction\Services\StoryDocumentFactory;
use LumoraFiction\Services\StoryDownloads;
use LumoraFiction\Controllers\NewsController;
use LumoraFiction\Controllers\Admin\PrivacyRequestsController;
use LumoraFiction\Controllers\Admin\CommentsController as AdminCommentsController;
use LumoraFiction\Controllers\Admin\CommentSettingsController;
use LumoraFiction\Controllers\CommentController;
use LumoraFiction\Controllers\ThemeChoiceController;
use LumoraFiction\Services\ActivityDescriber;
use LumoraFiction\Services\CommentGuard;
use LumoraFiction\Services\CommentNotifier;
use LumoraFiction\Services\CommentPresenter;
use LumoraFiction\Services\CommentService;
use LumoraFiction\Services\CommentSettings;
use LumoraFiction\Controllers\Admin\SiteLocationController;
use LumoraFiction\Controllers\Admin\PrivacySettingsController;
use LumoraFiction\Controllers\PrivacyController;
use LumoraFiction\Services\AccountDataExporter;
use LumoraFiction\Services\AccountDeletionService;
use LumoraFiction\Services\InstallPingService;
use LumoraFiction\Services\CacheSettings;
use LumoraFiction\Core\Cache\PageCache;
use LumoraFiction\Core\Cache\PurgeRules;
use LumoraFiction\Controllers\Admin\CacheSettingsController;
use LumoraFiction\Services\PrivacySettings;
use LumoraFiction\Controllers\Admin\PageController as AdminPageController;
use LumoraFiction\Controllers\PageController;
use LumoraFiction\Core\Theme\PagePresentation;
use LumoraFiction\Services\PagePresenter;
use LumoraFiction\Services\PageService;
use LumoraFiction\Services\NewsPresenter;
use LumoraFiction\Services\NewsService;
use LumoraFiction\Services\NewsSettings;
use LumoraFiction\Controllers\Admin\NotificationsController;
use LumoraFiction\Controllers\Admin\RetentionSettingsController;
use LumoraFiction\Controllers\Admin\TrashController;
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
use LumoraFiction\Controllers\Admin\TermSuggestController;
use LumoraFiction\Controllers\Admin\UsersController;
use LumoraFiction\Services\UserAdminService;
use LumoraFiction\Core\Content\HtmlSanitizer;
use LumoraFiction\Core\Content\MarkdownParser;
use LumoraFiction\Controllers\Admin\AuthController;
use LumoraFiction\Controllers\Admin\AuthorProfileController;
use LumoraFiction\Controllers\Admin\DashboardController;
use LumoraFiction\Controllers\Admin\SystemInformationController;
use LumoraFiction\Core\Admin\AdminMenu;
use LumoraFiction\Core\Admin\AdminView;
use LumoraFiction\Core\Admin\BackupListing;
use LumoraFiction\Core\Admin\CoreAlerts;
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
use LumoraFiction\Core\Theme\ThemeArchive;
use LumoraFiction\Core\Theme\ThemeCatalog;
use LumoraFiction\Core\Theme\ThemeInstaller;
use LumoraFiction\Core\Theme\ReadingPositionScript;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Core\Theme\ThemeScreenshot;
use LumoraFiction\Controllers\AccountController;
use LumoraFiction\Controllers\ArchiveController;
use LumoraFiction\Core\Theme\AccountNavigation;
use LumoraFiction\Core\Theme\ReaderHead;
use LumoraFiction\Controllers\PublicController;
use LumoraFiction\Controllers\FollowController;
use LumoraFiction\Controllers\ReaderController;
use LumoraFiction\Controllers\ReadingListController;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AkismetClient;
use LumoraFiction\Services\ArchiveSettings;
use LumoraFiction\Services\BookmarkPresenter;
use LumoraFiction\Services\FormGuard;
use LumoraFiction\Services\RegistrationGuard;
use LumoraFiction\Services\SecurityEventLog;
use LumoraFiction\Services\SecuritySettings;
use LumoraFiction\Services\SiteAlerts;
use LumoraFiction\Services\SiteLocation;
use LumoraFiction\Services\SpamSettings;
use LumoraFiction\Services\BookmarkService;
use LumoraFiction\Services\FollowPresenter;
use LumoraFiction\Services\StoryFollowService;
use LumoraFiction\Services\PluginManager;
use LumoraFiction\Services\PublicArchive;
use LumoraFiction\Services\ReaderPreferences;
use LumoraFiction\Services\ReadingProgressService;
use LumoraFiction\Services\SearchPresenter;
use LumoraFiction\Services\SearchQueryParser;
use LumoraFiction\Services\EditorSettings;
use LumoraFiction\Services\AuditLogService;
use LumoraFiction\Services\AuthorProfileService;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\ChapterService;
use LumoraFiction\Services\ClassificationLabelSettings;
use LumoraFiction\Services\CollectionService;
use LumoraFiction\Services\ExportService;
use LumoraFiction\Services\GroupingSettings;
use LumoraFiction\Services\SeriesService;
use LumoraFiction\Services\ClassificationPresets;
use LumoraFiction\Services\NotificationService;
use LumoraFiction\Services\RetentionSettings;
use LumoraFiction\Services\StoryNotifier;
use LumoraFiction\Services\ContentRenderer;
use LumoraFiction\Core\Media\AvatarImageStore;
use LumoraFiction\Core\Media\CoverImageStore;
use LumoraFiction\Core\Media\ThemePictureStore;
use LumoraFiction\Services\ThemePictures;
use LumoraFiction\Services\StoryPresenter;
use LumoraFiction\Services\StoryCardFacts;
use LumoraFiction\Services\StoryService;
use LumoraFiction\Services\TaxonomyService;
use LumoraFiction\Services\ThemeManager;
use LumoraFiction\Services\ThemeOptions;
use LumoraFiction\Services\ThemeChoice;
use LumoraFiction\Services\ThemeSettings;
use LumoraFiction\Services\UserService;

/**
 * One Kernel is built per request and handed to the entry script, instead of services being
 * reached through global state. Later features add their services here.
 */
final class Kernel
{
    /**
     * The admin layout, set once the admin screens are wired, so a plugin's own screen can be shown inside it.
     */
    public ?AdminView $admin = null;

    /**
     * Where a plugin raises or clears a stored alert for the Administrators, once the kernel has booted.
     */
    public ?SiteAlerts $alerts = null;

    /**
     * Whether visitors are being turned away, set once the kernel has booted; the front controller's own check runs earlier.
     */
    public ?MaintenanceMode $maintenance = null;

    /**
     * Reader comments, set once the kernel has booted.
     */
    public ?CommentService $comments = null;

    public ?CommentGuard $commentGuard = null;

    /**
     * Applies database changes that arrived with newly uploaded files, when an Administrator opens the admin.
     */
    public ?PendingMigrations $pendingMigrations = null;

    /**
     * Asks GitHub for a new release when an Administrator has turned the automatic check on and one is due.
     */
    public ?UpdateChecker $updateChecker = null;

    /**
     * Decides which theme public pages are drawn in: an Administrator's preview, a visitor's choice, or the site's theme.
     */
    public ?ThemeChoice $themeChoice = null;

    /**
     * The site's folder on disk, for plugins that keep files under storage/. A plugin file may sit somewhere else
     * than the site it runs in, so it must not work this out from its own location.
     */
    public string $root = '';

    /**
     * Story downloads, built with the other services at boot. Nullable only because the kernel is constructed before they are.
     */
    public ?DownloadAccess $downloadAccess = null;

    public ?StoryDownloads $storyDownloads = null;

    public ?DownloadCounter $downloadCounter = null;

    /**
     * Static pages and the object that links them from the menus, built at boot for the same reason.
     */
    public ?PageService $pages = null;

    public ?SeriesService $series = null;

    public ?CollectionService $collections = null;

    public ?PagePresentation $pagePresentation = null;

    /** Redirects from an old address to a new one, answered for any address no page of the site owns. */
    public ?RedirectService $redirects = null;

    /** What the head of a public page says about feeds and about search engines; controllers tell them which page is shown. */
    public ?FeedLinks $feedLinks = null;

    public ?SearchHead $searchHead = null;

    /**
     * Privacy settings, the data export and account deletion, built with the other services at boot.
     */
    public ?PrivacySettings $privacy = null;

    public ?InstallPingService $installPing = null;

    /** Cache headers, conditional answers and purge instructions for public pages. */
    public ?PageCache $pageCache = null;

    public ?AccountDataExporter $accountExporter = null;

    public ?AccountDeletionService $accountDeletion = null;

    /** The shared story, chapter, classification and inbox services, built once at boot for the screens and for plugins. */
    public ?StoryService $stories = null;

    public ?ChapterService $chapters = null;

    public ?TaxonomyService $taxonomy = null;

    public ?NotificationService $notifications = null;

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

        $config->registerDefaults(AuthService::DEFAULTS + ArchiveSettings::DEFAULTS + EditorSettings::DEFAULTS + TaxonomyService::DEFAULTS + ThemeSettings::DEFAULTS + PluginManager::DEFAULTS + SpamSettings::DEFAULTS + SecurityEventLog::DEFAULTS + SecuritySettings::DEFAULTS + SiteAlerts::DEFAULTS + RetentionSettings::DEFAULTS + NewsSettings::DEFAULTS + FrontPageSettings::DEFAULTS + DownloadSettings::DEFAULTS + FeedSettings::DEFAULTS + MaintenanceMode::DEFAULTS + UpdateChecker::DEFAULTS + PrivacySettings::DEFAULTS + InstallPingService::DEFAULTS + CacheSettings::DEFAULTS + CommentSettings::DEFAULTS + ThemeChoice::DEFAULTS + SearchSettings::DEFAULTS);

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

        $audit = new AuditLogService($database);
        $mailer = new NativeMailer('noreply@' . (parse_url($siteUrl, PHP_URL_HOST) ?: 'localhost'));
        $alerts = new SiteAlerts($database, $config, $hooks, $audit, $mailer, (string) $config->setting('site.name', 'Lumora Fiction'), $siteUrl);
        (new CoreAlerts($config, $root, new SiteLocation($config, $root, $audit, $root . '/config/config.php')))->register($hooks);
        $notifications = new NotificationService($database);
        (new StoryNotifier($notifications, $database))->register($hooks);
        $securityEvents = new SecurityEventLog($database, $config, $alerts);
        $securitySettings = new SecuritySettings($config, $database, $audit);
        $lockout = $securitySettings->current();

        $auth = new AuthService(
            $users,
            $sessions,
            new TokenService($database),
            $mailer,
            new LoginThrottle($database, $lockout['lockout_attempts'], $lockout['lockout_minutes'] * 60, $lockout['lockout_minutes'] * 60, $lockout['lockout_progressive']),
            $passwords,
            $config,
            $siteUrl,
            events: $securityEvents,
            alerts: $alerts,
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
        $themePictures = new ThemePictures($root . '/content/uploads/theme-headers', 'content/uploads/theme-headers', new ThemePictureStore($root . '/content/uploads/theme-headers/uploaded'));
        $themeOptions = new ThemeOptions($config, $hooks, $audit, $themePictures);
        $themes = new ThemeRenderer($themeCatalog, $config, $hooks, $themeOptions, $errors, $version['version']);

        $kernel = new self($config, $database, $errors, $hooks, $router, $sessions, $users, $auth, $audit, $themes, $isHttps, $proxies);
        $kernel->root = $root;
        $kernel->alerts = $alerts;
        $kernel->maintenance = new MaintenanceMode($config, $database, $audit);
        $kernel->pendingMigrations = new PendingMigrations(new Migrator($database, $root . '/app/Migrations'), $audit, $alerts, new FlashMessages($sessions));

        $kernel->themeChoice = new ThemeChoice($themeCatalog, $themes, $config, $auth, $sessions, new ReaderPreferences($database), $audit, $version['version'], (string) $config->get('secret_key', ''));
        $themes->setThemeTools(static fn (string $kind): ?array => match ($kind) {
            'switch' => $kernel->themeChoice?->switchData(),
            'banner' => $kernel->themeChoice?->bannerData(),
            default => null,
        });

        (new StaffNavigation($auth))->register($hooks);

        // The public pages and the admin screens work on the same services, so each is built once.
        $taxonomy = new TaxonomyService($kernel->database, $kernel->config, $kernel->audit, $kernel->hooks);
        $covers = new CoverImageStore($root . '/content/uploads/covers');
        $stories = new StoryService($kernel->database, $kernel->config, $kernel->audit, $taxonomy, $kernel->hooks, $covers);
        $chapters = new ChapterService($kernel->database, $stories, $kernel->audit, $kernel->config);
        $kernel->stories = $stories;
        $kernel->chapters = $chapters;
        $kernel->taxonomy = $taxonomy;
        $kernel->notifications = $notifications;
        $renderer = new ContentRenderer(new MarkdownParser(), new HtmlSanitizer(), $kernel->hooks);
        $presenter = new StoryPresenter($covers, $taxonomy->labels());
        $news = new NewsService($kernel->database, $kernel->audit, $kernel->hooks);
        $newsPresenter = new NewsPresenter($renderer, $kernel->users);
        // The closure is evaluated at save time, so a route a plugin adds later still counts as taken.
        $kernel->pages = new PageService($kernel->database, $kernel->audit, static fn (string $slug): bool => $router->isRouted('/' . $slug), $kernel->hooks);
        $kernel->pagePresentation = new PagePresentation($kernel->pages, rtrim((string) $config->get('site_url', ''), '/'));
        $kernel->pagePresentation->register($hooks);

        // A moved page or a merged term leaves its old address answering with a redirect.
        $kernel->redirects = new RedirectService($database, $audit);
        $hooks->addAction('page_slug_changed', static function (string $old, string $new) use ($kernel): void {
            $kernel->redirects?->remember(PublicUrl::page($old, false), PublicUrl::page($new, false));
        });
        $hooks->addAction('term_merged', static function (Term $source, Term $target) use ($kernel): void {
            $kernel->redirects?->remember(
                BasePath::stripFrom(PublicUrl::term($source->type, $source->slug)),
                BasePath::stripFrom(PublicUrl::term($target->type, $target->slug)),
            );
        });
        $kernel->feedLinks = new FeedLinks($config, $siteUrl, static fn (): bool => $kernel->comments?->settings()->enabled() === true);
        $kernel->feedLinks->register($hooks);
        $kernel->searchHead = new SearchHead($config, $siteUrl);
        $kernel->searchHead->register($hooks);
        $avatars = new AvatarImageStore($root . '/content/uploads/avatars');
        $authorProfiles = new AuthorProfileService($kernel->database, $renderer, $avatars, $kernel->audit);

        $kernel->series = new SeriesService($database, $audit, $stories, $hooks);
        $kernel->collections = new CollectionService($database, $config, $audit, $stories, $hooks);
        GroupingSettings::hideFromMenu($hooks, $config);

        $kernel->privacy = new PrivacySettings($config, $database, $audit, $kernel->pages);
        $kernel->pageCache = new PageCache(
            new CacheSettings($config, $database, $audit),
            $hooks,
            static fn (): bool => $config->setting('reading.require_signin') === true,
            static fn (): bool => $kernel->themeChoice?->enabled() === true,
            $root . '/storage/cache/purge-log.json',
        );
        // Every change to the site is written to the audit log, so one listener there knows when a cached page went stale.
        $purgeRules = new PurgeRules($database);
        $audit->listen(static function (string $action, ?string $targetType, ?string $targetId) use ($kernel, $purgeRules): void {
            $kernel->pageCache?->purge($purgeRules->scopeFor($action, $targetType, $targetId));
        });
        $kernel->installPing = new InstallPingService($config, new HttpClient('Lumora Fiction/' . $version['version']), $version['version']);
        $kernel->comments = new CommentService($database, new CommentSettings($config, $audit), $audit, $hooks);
        $kernel->commentGuard = new CommentGuard($formGuard, $spamSettings, $akismet);
        (new CommentNotifier($notifications, $kernel->comments, $database, $mailer, $siteUrl, (string) $config->setting('site.name', 'Lumora Fiction')))->register($hooks);
        // A deleted account keeps its comments under the neutral name; what it reported and its own settings go.
        $hooks->addAction('account_deleted', static function (int $userId) use ($kernel): void {
            $kernel->comments?->forgetUser($userId);
        });
        $kernel->accountExporter = new AccountDataExporter($database, $kernel->comments);
        $kernel->accountDeletion = new AccountDeletionService(
            $database,
            $stories,
            new BookmarkService($database, $stories),
            new ReadingProgressService($database, $stories),
            new StoryFollowService($database, $stories),
            $kernel->privacy,
            $audit,
            $avatars,
            $hooks,
        );
        // The privacy policy reaches every theme through one filter, so a theme never learns how the page is chosen.
        $hooks->addFilter('lf_privacy_policy', static fn (mixed $link): mixed => $kernel->privacy?->link() ?? $link);
        $flash = new FlashMessages($sessions);
        $editorSettings = new EditorSettings($config, $database, $audit);

        $kernel->downloadAccess = new DownloadAccess($config, $database, $stories, $audit);
        $kernel->downloadCounter = new DownloadCounter($database);
        $kernel->storyDownloads = new StoryDownloads($config, new StoryDocumentFactory($chapters, $taxonomy, $kernel->users, $renderer, $covers), $root . '/storage/cache/downloads', __DIR__ . '/../Libraries');

        // A deleted story takes its files and totals with it.
        $hooks->addAction('story_deleted', static function (int $storyId) use ($kernel): void {
            $kernel->storyDownloads?->forget($storyId);
            $kernel->downloadCounter?->forget($storyId);
        });

        $pluginCatalog = new PluginCatalog($root . '/content/plugins');
        $plugins = new PluginManager($config, $pluginCatalog, new PluginLoader($kernel, $pluginCatalog, $version['version'], $root), $audit, $hooks, $errors, $version['version']);
        (new PluginNotices($plugins))->register($hooks);

        self::registerPublic($kernel, $themeOptions, $taxonomy, $stories, $chapters, $renderer, $presenter, $flash, $editorSettings, $registrationGuard, $formGuard, $authorProfiles, $news, $newsPresenter);
        self::registerAdmin($kernel, $root, $version['version'], $themeCatalog, $themeOptions, $taxonomy, $stories, $chapters, $renderer, $presenter, $flash, $editorSettings, $plugins, $spamSettings, $registrationThrottle, $akismet, $securityEvents, $securitySettings, $authorProfiles, $alerts, $notifications, $news, $newsPresenter);

        // Plugins load after the core routes are registered, so none of them can take over a core address.
        $plugins->loadActive();
        $hooks->doAction('plugins_loaded', $kernel);

        // Plugins and later features add their routes here.
        $hooks->doAction('routes_register', $router);

        // A page's address is a single path segment. It is the router's route of last resort, so it answers only an
        // address that nothing else, a plugin's route included, owns, and a POST to an unknown address stays a 404.
        $pagePages = new PageController($kernel->themes, $kernel->pages, new PagePresenter($renderer), $kernel->pagePresentation, $kernel->auth);
        $router->fallbackGet(PublicUrl::PAGE, $pagePages->show(...));
        $hooks->doAction('kernel_booted', $kernel);

        return $kernel;
    }

    /**
     * The public site's own addresses. Plugins add theirs through routes_register, after these.
     */
    private static function registerPublic(self $kernel, ThemeOptions $themeOptions, TaxonomyService $taxonomy, StoryService $stories, ChapterService $chapters, ContentRenderer $renderer, StoryPresenter $presenter, FlashMessages $flash, EditorSettings $editorSettings, RegistrationGuard $registrationGuard, FormGuard $formGuard, AuthorProfileService $authorProfiles, NewsService $news, NewsPresenter $newsPresenter): void
    {
        $readerPreferences = new ReaderPreferences($kernel->database);
        $commentService = $kernel->comments ?? throw new \LogicException('Built at boot.');
        $commentPresenter = new CommentPresenter($commentService);
        $bookmarks = new BookmarkService($kernel->database, $stories);
        $bookmarkPresenter = new BookmarkPresenter($bookmarks);
        $storyFollows = new StoryFollowService($kernel->database, $stories);
        $followPresenter = new FollowPresenter();
        $account = new AccountController($kernel->themes, $kernel->auth, $kernel->users, $editorSettings, $flash, $kernel->config, $kernel->audit, $readerPreferences, $registrationGuard, $formGuard, $storyFollows, $kernel->privacy, $commentService);
        $privacyPages = new PrivacyController($kernel->themes, $kernel->auth, $flash, $kernel->accountExporter ?? throw new \LogicException('Built at boot.'), $kernel->accountDeletion ?? throw new \LogicException('Built at boot.'), $kernel->privacy ?? throw new \LogicException('Built at boot.'), $kernel->audit);
        $cardFacts = new StoryCardFacts($chapters, $taxonomy);
        $reader = new ReaderController($kernel->themes, $kernel->auth, $bookmarks, $bookmarkPresenter, $stories, $presenter, $kernel->users, $flash, $storyFollows, $followPresenter, $cardFacts);
        $readingProgress = new ReadingProgressService($kernel->database, $stories);
        $readingList = new ReadingListController($kernel->themes, $kernel->auth, $readingProgress, $presenter, $kernel->users, $flash, $storyFollows, $readerPreferences, $cardFacts);
        $follow = new FollowController($kernel->themes, $kernel->auth, $storyFollows, $followPresenter, $stories, $presenter, $kernel->users, $flash, $cardFacts);
        (new AccountNavigation($kernel->auth, $kernel->config))->register($kernel->hooks);
        (new ReaderHead($kernel->auth, $readerPreferences))->register($kernel->hooks);
        $positionScript = new ReadingPositionScript();
        $positionScript->register($kernel->hooks);

        $public = new PublicController($kernel->themes, $themeOptions, __DIR__ . '/Theme/fallback/style.css');
        $publicArchive = new PublicArchive($kernel->database, $stories);
        $searchParser = new SearchQueryParser($taxonomy, $kernel->config);
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
            $readingProgress,
            $flash,
            $authorProfiles,
            $positionScript,
            $storyFollows,
            $followPresenter,
            $readerPreferences,
            $news,
            $newsPresenter,
            $kernel->downloadAccess !== null && $kernel->storyDownloads !== null && $kernel->downloadCounter !== null
                ? new DownloadPresenter($kernel->config, $kernel->downloadAccess, $kernel->storyDownloads, $kernel->downloadCounter)
                : null,
            $commentPresenter,
            $kernel->feedLinks,
            $kernel->searchHead,
            $kernel->series,
            $kernel->collections,
        );
        $newsPages =new NewsController($kernel->themes, $news, $newsPresenter, $kernel->auth, $kernel->config, $kernel->feedLinks);

        $kernel->router->get('/', $archive->home(...), 'home');
        $kernel->router->get(PublicUrl::SEARCH, $archive->search(...), 'search');
        $kernel->router->get(PublicUrl::BROWSE, $archive->browse(...), 'browse');
        $kernel->router->get(PublicUrl::BROWSE_PAGE, $archive->browse(...), 'browse.page');
        $kernel->router->get('/theme-options.css', $public->themeOptionsStylesheet(...), 'theme.options.css');
        $kernel->router->post('/theme', (new ThemeChoiceController($kernel->themeChoice ?? throw new \LogicException('Built at boot.'), $kernel->auth))->choose(...), 'theme.choose');
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
        $kernel->router->post(PublicUrl::COMMENT_PREFERENCES, $account->updateComments(...), 'account.profile.comments');

        // Route patterns never carry the install folder, so these are written out rather than built with PublicUrl::account().
        $kernel->router->get('/account/privacy', $privacyPages->show(...), 'account.privacy');
        $kernel->router->post('/account/privacy/export', $privacyPages->export(...), 'account.privacy.export');
        $kernel->router->post('/account/privacy/delete', $privacyPages->delete(...), 'account.privacy.delete');

        $kernel->router->get(PublicUrl::BOOKMARKS, $reader->list(...), 'account.bookmarks');
        $kernel->router->post(PublicUrl::BOOKMARK_ADD, $reader->add(...), 'account.bookmarks.add');
        $kernel->router->post(PublicUrl::BOOKMARK_REMOVE, $reader->remove(...), 'account.bookmarks.remove');

        $kernel->router->get(PublicUrl::CONTINUE, $readingList->list(...), 'account.continue');
        $kernel->router->post(PublicUrl::CONTINUE_FORGET, $readingList->forget(...), 'account.continue.forget');
        $kernel->router->get(PublicUrl::CONTINUE_CLEAR, $readingList->confirmClear(...), 'account.continue.clear');
        $kernel->router->post(PublicUrl::CONTINUE_CLEAR, $readingList->clear(...), 'account.continue.clear.submit');
        $kernel->router->post(PublicUrl::READING_POSITION, $readingList->savePosition(...), 'account.reading.position');

        $kernel->router->get(PublicUrl::UPDATES, $follow->updates(...), 'account.updates');
        $kernel->router->post(PublicUrl::UPDATES_FOLLOW, $follow->follow(...), 'account.updates.follow');
        $kernel->router->post(PublicUrl::UPDATES_STOP, $follow->stop(...), 'account.updates.stop');
        $kernel->router->get(PublicUrl::UPDATES_STOP_ALL, $follow->confirmStopAll(...), 'account.updates.stop_all');
        $kernel->router->post(PublicUrl::UPDATES_STOP_ALL, $follow->stopAll(...), 'account.updates.stop_all.submit');

        $kernel->router->get(PublicUrl::NEWS, $newsPages->archive(...), 'news');
        $kernel->router->get(PublicUrl::NEWS_POST, $newsPages->post(...), 'news.post');

        $siteUrl = (string) $kernel->config->get('site_url', '');
        (new SearchEngineController(
            new SitemapService($kernel->database, $kernel->config, $taxonomy, $kernel->pages ?? throw new \LogicException('Built at boot.'), rtrim($siteUrl, '/')),
            $kernel->themes,
            $kernel->config,
            rtrim($siteUrl, '/'),
        ))->register($kernel->router);
        (new FeedController(
            new FeedService($kernel->config, $stories, $chapters, $publicArchive, $cardFacts, $taxonomy, $kernel->users, $news, $commentService, $presenter, $renderer, $siteUrl, $kernel->series, $kernel->collections),
            $kernel->themes,
            $kernel->config,
        ))->register($kernel->router);

        $kernel->router->get(PublicUrl::STORY, $archive->story(...), 'story');
        $kernel->router->get(PublicUrl::CHAPTER, $archive->chapter(...), 'chapter');

        $commentPages = new CommentController($kernel->themes, $kernel->auth, $commentService, $commentPresenter, $kernel->commentGuard ?? throw new \LogicException('Built at boot.'), $stories, $chapters, $flash);
        $kernel->router->post(PublicUrl::COMMENT_POST, $commentPages->post(...), 'comment.post');
        $kernel->router->get(PublicUrl::COMMENT_EDIT, $commentPages->showEdit(...), 'comment.edit');
        $kernel->router->post(PublicUrl::COMMENT_EDIT, $commentPages->edit(...), 'comment.edit.submit');
        $kernel->router->get(PublicUrl::COMMENT_DELETE, $commentPages->showDelete(...), 'comment.delete.confirm');
        $kernel->router->post(PublicUrl::COMMENT_DELETE, $commentPages->delete(...), 'comment.delete');
        $kernel->router->get(PublicUrl::COMMENT_REPLY, $commentPages->showReply(...), 'comment.reply');
        $kernel->router->post(PublicUrl::COMMENT_REPLY, $commentPages->reply(...), 'comment.reply.submit');
        $kernel->router->get(PublicUrl::COMMENT_REPORT, $commentPages->showReport(...), 'comment.report');
        $kernel->router->post(PublicUrl::COMMENT_REPORT, $commentPages->report(...), 'comment.report.submit');

        if ($kernel->downloadAccess !== null && $kernel->storyDownloads !== null && $kernel->downloadCounter !== null) {
            $downloads = new DownloadController($kernel->themes, $stories, $kernel->downloadAccess, $kernel->storyDownloads, $kernel->downloadCounter, $kernel->auth, $kernel->config);
            $kernel->router->get(PublicUrl::DOWNLOAD, $downloads->download(...), 'story.download');
        }

        $kernel->router->get(PublicUrl::AUTHOR, $archive->author(...), 'author');
        $kernel->router->get(PublicUrl::SERIES, $archive->series(...), 'series');
        $kernel->router->get(PublicUrl::COLLECTIONS, $archive->collections(...), 'collections');
        $kernel->router->get(PublicUrl::COLLECTION, $archive->collection(...), 'collection');

        // One route per kind of term, so an address under any other first segment is never read as a term.
        foreach (TermType::cases() as $type) {
            $kernel->router->get(PublicUrl::termPattern($type), static fn (array $params, Request $request): Response => $archive->term($type, $params, $request), 'term.' . $type->value);
        }
    }

    /**
     * Wires the admin screens and adds their addresses to the router.
     */
    private static function registerAdmin(self $kernel, string $root, string $version, ThemeCatalog $themeCatalog, ThemeOptions $themeOptions, TaxonomyService $taxonomy, StoryService $stories, ChapterService $chapters, ContentRenderer $renderer, StoryPresenter $presenter, FlashMessages $flash, EditorSettings $editorSettings, PluginManager $plugins, SpamSettings $spamSettings, RegistrationThrottle $registrationThrottle, AkismetClient $akismet, SecurityEventLog $securityEvents, SecuritySettings $securitySettings, AuthorProfileService $authorProfiles, SiteAlerts $alerts, NotificationService $notifications, NewsService $news, NewsPresenter $newsPresenter): void
    {
        $maintenanceNotice = new MaintenanceNotice($kernel->maintenance ?? new MaintenanceMode($kernel->config, $kernel->database, $kernel->audit), $root);
        $view = new AdminView(
            $root . '/admin/views',
            new AdminMenu($kernel->hooks),
            (string) $kernel->config->setting('site.name', 'Lumora Fiction'),
            $version,
            static fn (User $user): int => $alerts->badgeCount($user),
            static fn (User $user): int => $notifications->countUnread($user),
            static fn (User $user): array => $maintenanceNotice->forUser($user),
            static fn (User $user): int => $kernel->comments?->queueCount($user) ?? 0,
            static fn (User $user): bool => $user->can(Capability::ManageSite) && $kernel->updateChecker?->status() === UpdateChecker::STATUS_AVAILABLE,
        );
        $kernel->admin = $view;

        $editor = new ContentEditor($view, $editorSettings, $version);

        $updateStorage = new UpdateStorage($root);
        $backups = new BackupService($updateStorage, $kernel->database, $version);
        $backupListing = new BackupListing($backups);
        $updates = new UpdateService(
            $updateStorage,
            $backups,
            new PackageInspector(),
            new CompatibilityChecker($updateStorage, $kernel->database, $version),
            new Migrator($kernel->database, $root . '/app/Migrations'),
            $kernel->maintenance ?? new MaintenanceMode($kernel->config, $kernel->database, $kernel->audit),
            $kernel->audit,
            $kernel->errors,
            $alerts,
        );
        $updateChecker = new UpdateChecker($kernel->config, $kernel->database, $kernel->audit, new ReleaseFeed(new HttpClient('LumoraFiction-Updater/' . $version)), $updateStorage, $alerts, $version);
        $kernel->updateChecker = $updateChecker;

        $themeSettings = new ThemeSettings($kernel->config, $themeCatalog, $kernel->audit);

        AdminRoutes::register(
            $kernel->router,
            new AuthController($kernel->auth, $view, $flash),
            new DashboardController($kernel->auth, $view, $flash, $kernel->users, $kernel->audit, $alerts, $stories, $news, $maintenanceNotice, new ActivityDescriber(), $kernel->comments),
            new ArchiveSettingsController($kernel->auth, $view, $flash, new ArchiveSettings($kernel->config, $kernel->database, $kernel->audit), new ClassificationPresets($kernel->database, $kernel->config, $taxonomy, $kernel->audit, __DIR__ . '/../Data/presets')),
            new EditorSettingsController($kernel->auth, $view, $flash, $editorSettings),
            new ContentEditorController($kernel->auth, $renderer),
            new TermSuggestController($kernel->auth, $taxonomy),
            new AuthorProfileController($kernel->auth, $view, $flash, $authorProfiles, $editor, $kernel->downloadAccess, $kernel->config),
            new TaxonomyController($kernel->auth, $view, $flash, $taxonomy, new ClassificationLabelSettings($kernel->config, $kernel->database, $kernel->audit)),
            new StoryController($kernel->auth, $view, $flash, $stories, $chapters, $taxonomy, $kernel->users, $kernel->themes, $presenter, $kernel->downloadAccess, $kernel->config, $kernel->comments, $kernel->series),
            new ChapterController($kernel->auth, $view, $flash, $stories, $chapters, $editor, $renderer, $kernel->users, $kernel->themes, $presenter, $kernel->comments),
            new ReviewController($kernel->auth, $view, $flash, $stories, $chapters, $kernel->users),
            new UsersController($kernel->auth, $view, $flash, new UserAdminService($kernel->database, $kernel->users, $stories, $kernel->audit, $kernel->config, $kernel->hooks), $kernel->users, $stories, $kernel->config, $authorProfiles),
            new AppearanceController($kernel->auth, $view, $flash, $kernel->themes, $themeSettings, $themeOptions, new ThemeManager(
                new ThemeInstaller($root . '/content/themes', new ThemeArchive($version), $themeCatalog),
                $themeCatalog,
                $kernel->themes,
                $themeSettings,
                $themeOptions,
                $kernel->audit,
                $kernel->config,
                $version,
            ), new ThemeScreenshot(), $kernel->themeChoice ?? throw new \LogicException('Built at boot.')),
            new SystemInformationController($kernel->auth, $view, $flash, $root, $kernel->config, $kernel->database),
            new PluginsController($kernel->auth, $view, $flash, $plugins),
            new SpamSettingsController($kernel->auth, $view, $flash, $spamSettings, $registrationThrottle, $akismet, $kernel->audit),
            new SecurityEventsController($kernel->auth, $view, $flash, $securityEvents),
            new SecuritySettingsController($kernel->auth, $view, $flash, $securitySettings),
            new AlertsController($kernel->auth, $view, $flash, $alerts),
            new TrashController($kernel->auth, $view, $flash, $stories, $chapters, $kernel->users, $kernel->config),
            new NotificationsController($kernel->auth, $view, $flash, $notifications),
            new RetentionSettingsController($kernel->auth, $view, $flash, new RetentionSettings($kernel->config, $kernel->database, $kernel->audit)),
            new AdminNewsController($kernel->auth, $view, $flash, $news, $editor, $kernel->themes, $newsPresenter, $kernel->config),
            new NewsSettingsController($kernel->auth, $view, $flash, new NewsSettings($kernel->config, $kernel->database, $kernel->audit)),
            new FrontPageSettingsController($kernel->auth, $view, $flash, new FrontPageSettings($kernel->config, $kernel->database, $kernel->audit)),
            new DownloadSettingsController($kernel->auth, $view, $flash, new DownloadSettings($kernel->config, $kernel->database, $kernel->audit), $kernel->storyDownloads),
            new UpdatesController($kernel->auth, $view, $flash, $updates, $updateChecker, $updateStorage, $kernel->audit, $backupListing, new ReleaseNotes()),
            new BackupsController($kernel->auth, $view, $flash, $backups, $updates, $kernel->audit, $backupListing),
            new MaintenanceModeController($kernel->auth, $view, $flash, $kernel->maintenance ?? new MaintenanceMode($kernel->config, $kernel->database, $kernel->audit)),
            new AdminPageController($kernel->auth, $view, $flash, $kernel->pages ?? throw new \LogicException('Pages are built at boot.'), $editor, $kernel->themes, new PagePresenter($renderer)),
            new PrivacySettingsController($kernel->auth, $view, $flash, $kernel->privacy ?? throw new \LogicException('Built at boot.'), $kernel->pages, static fn (): InstallPingService => $kernel->installPing ?? throw new \LogicException('Built at boot.')),
            new PrivacyRequestsController($kernel->auth, $view, $flash, $kernel->users, $kernel->accountExporter ?? throw new \LogicException('Built at boot.'), $kernel->accountDeletion ?? throw new \LogicException('Built at boot.'), $kernel->privacy, $kernel->audit),
            new AdminCommentsController($kernel->auth, $view, $flash, $kernel->comments ?? throw new \LogicException('Built at boot.')),
            new CommentSettingsController($kernel->auth, $view, $flash, new CommentSettings($kernel->config, $kernel->audit)),
            new SiteLocationController($kernel->auth, $view, $flash, new SiteLocation($kernel->config, $root, $kernel->audit, $root . '/config/config.php'), $kernel->config),
            new FeedSettingsController($kernel->auth, $view, $flash, new FeedSettings($kernel->config, $kernel->database, $kernel->audit), $kernel->config),
            new SearchSettingsController($kernel->auth, $view, $flash, new SearchSettings($kernel->config, $kernel->database, $kernel->audit), $kernel->config, rtrim((string) $kernel->config->get('site_url', ''), '/')),
            new RedirectsController($kernel->auth, $view, $flash, $kernel->redirects ?? throw new \LogicException('Built at boot.')),
            new SettingsTransferController($kernel->auth, $view, $flash, new SettingsTransfer(
                new ArchiveSettings($kernel->config, $kernel->database, $kernel->audit),
                $securitySettings,
                $kernel->privacy ?? throw new \LogicException('Built at boot.'),
                new CommentSettings($kernel->config, $kernel->audit),
                new DownloadSettings($kernel->config, $kernel->database, $kernel->audit),
                new FeedSettings($kernel->config, $kernel->database, $kernel->audit),
                new ClassificationLabelSettings($kernel->config, $kernel->database, $kernel->audit),
                new NewsSettings($kernel->config, $kernel->database, $kernel->audit),
                new FrontPageSettings($kernel->config, $kernel->database, $kernel->audit),
                $editorSettings,
                new RetentionSettings($kernel->config, $kernel->database, $kernel->audit),
                new SearchSettings($kernel->config, $kernel->database, $kernel->audit),
                $themeSettings,
                $kernel->themeChoice ?? throw new \LogicException('Built at boot.'),
                $themeOptions,
                $themeCatalog,
                $kernel->audit,
                $version,
            )),
            new SeriesController($kernel->auth, $view, $flash, $kernel->series ?? throw new \LogicException('Built at boot.'), $stories, $kernel->config),
            new CollectionController($kernel->auth, $view, $flash, $kernel->collections ?? throw new \LogicException('Built at boot.'), $stories, $kernel->config),
            new GroupingSettingsController($kernel->auth, $view, $flash, new GroupingSettings($kernel->config, $kernel->database, $kernel->audit)),
            new ExportController($kernel->auth, $view, $flash, new ExportService(
                $kernel->database,
                $kernel->config,
                $kernel->audit,
                $stories,
                $chapters,
                $taxonomy,
                $kernel->users,
                $kernel->series ?? throw new \LogicException('Built at boot.'),
                $kernel->collections ?? throw new \LogicException('Built at boot.'),
                new CoverImageStore($root . '/content/uploads/covers'),
                $root . '/storage/cache/exports',
                $version,
            ), $stories),
            new CacheSettingsController(
                $kernel->auth,
                $view,
                $flash,
                $kernel->pageCache?->settings() ?? throw new \LogicException('Built at boot.'),
                $kernel->pageCache ?? throw new \LogicException('Built at boot.'),
                static fn (): bool => $kernel->themeChoice?->enabled() === true,
                static fn (): bool => $kernel->config->setting('reading.require_signin') === true,
                static fn (): bool => $kernel->hooks->hasAction('story_viewed'),
            ),
        );
    }

    /**
     * Matches the request to a route and runs its handler, or sends a 404 or 405 page. The handler
     * is given the route's parameters and the Request.
     */
    public function handle(string $method, string $uri, ?Request $request = null): void
    {
        $this->sendSecurityHeaders();

        $request ??= Request::fromGlobals($method, $uri, $this->proxies);
        $held = $this->maintenanceResponse($request, BasePath::stripFrom($uri));

        if ($held !== null) {
            $held->send();

            return;
        }

        $this->upgradeDatabase($request, BasePath::stripFrom($uri));
        $this->checkForUpdates($request, BasePath::stripFrom($uri));
        $this->sendInstallPing($request, BasePath::stripFrom($uri));

        // After the maintenance check, which always shows the site's own theme, and before anything is drawn.
        $started = $this->selectTheme($request, BasePath::stripFrom($uri));

        if ($started !== null) {
            $started->send();

            return;
        }

        $resolution = $this->router->resolve($method, BasePath::stripFrom($uri));

        if ($resolution->isFound() && $resolution->handler !== null) {
            // The alerts checks that depend on the request (is this page on HTTPS) must see the one being answered.
            $this->alerts?->useRequest($request);
            $result = ($resolution->handler)($resolution->params, $request);

            // A handler may return a Response to be sent, or print its own output and return nothing.
            if ($result instanceof Response) {
                $this->finishResponse($request, $this->redirectFor($method, BasePath::stripFrom($uri), $result) ?? $result, $resolution->name, $resolution->params)->send();
            }

            return;
        }

        $redirect = $resolution->status === 404 ? $this->redirectFor($method, BasePath::stripFrom($uri), null) : null;

        $this->finishResponse($request, $redirect ?? $this->statusResponse($resolution->status, $resolution->allowedMethods), null)->send();
    }

    /**
     * The last step before a response is sent: cache headers on a public page, the answer that nothing changed when the
     * visitor already holds it, and any instruction to an outside cache that this request caused.
     *
     * @param array<string, string> $params
     */
    public function finishResponse(Request $request, Response $response, ?string $routeName, array $params = []): Response
    {
        try {
            return $this->pageCache?->apply($request, $response, $routeName, $params) ?? $response;
        } catch (Throwable $exception) {
            // Caching is an optimisation: whatever goes wrong here, the page itself is still sent, and not kept.
            $this->errors->report($exception);

            return $response->withHeader('Cache-Control', 'private, no-store');
        }
    }

    /**
     * The redirect for a request, or null. A redirect answers a GET or HEAD only where the site itself has nothing to
     * show: no route owns the address, or the page that does answers "not found" (an old story or term address, a page
     * that was moved). So a redirect can never take over a page that is really there. The query string is not part of an
     * address here and is not carried over.
     *
     * @param string $uri the request URI with the base path already removed
     * @param Response|null $answer what the site would send, or null when no route owns the address
     */
    public function redirectFor(string $method, string $uri, ?Response $answer): ?Response
    {
        if (($answer !== null && $answer->status !== 404) || !in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            return null;
        }

        $path = rawurldecode((string) strtok($uri, '?#'));
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $found = $path === '' || $path === '/' ? null : $this->redirects?->find($path);

        if ($found === null) {
            return null;
        }

        // A target that starts with a slash is a path on this site and gets the install folder back; anything else was
        // checked as a full http or https address when it was saved.
        $location = $found['target'][0] === '/' ? BasePath::get() . $found['target'] : $found['target'];

        return new Response('', $found['status'], ['Location' => $location]);
    }

    /**
     * Brings the database up to date for the files on disk when an Administrator opens the admin. Skipped while an update
     * runs, because the updater applies its own changes in a fixed order and may be about to put the old ones back.
     *
     * @param string $path the request path with the base path already removed
     */
    public function upgradeDatabase(Request $request, string $path): void
    {
        $path = (string) strtok($path, '?#');

        if ($this->pendingMigrations === null || ($path !== '/admin' && !str_starts_with($path, '/admin/')) || MaintenanceFlag::read($this->root) !== null) {
            return;
        }

        try {
            $user = $this->auth->user($request->cookies);
        } catch (Throwable $exception) {
            $this->errors->report($exception);

            return;
        }

        if ($user !== null && $user->can(Capability::ManageSite)) {
            $this->pendingMigrations->applyFor($user, $request->ip());
        }
    }

    /**
     * Chooses the theme this request is drawn in. A redirect comes back only when an Administrator has just started a
     * preview from a link, which sends them to the same page without the name in the address.
     *
     * @param string $path the request path with the base path already removed
     */
    public function selectTheme(Request $request, string $path): ?Response
    {
        if ($this->themeChoice === null) {
            return null;
        }

        try {
            return $this->themeChoice->begin($request, (string) strtok($path, '?#'));
        } catch (Throwable $exception) {
            // Choosing a theme must never stop a page from being drawn: the site's own theme is the answer.
            $this->errors->report($exception);
            $this->themes->useTheme(null);

            return null;
        }
    }

    /**
     * The automatic check for a new release: made at most once per chosen interval, only for an Administrator opening an
     * admin page, never during an update or on the progress screen's own requests, and it only ever reads the release
     * list. A failure is remembered and not retried until the next interval.
     *
     * @param string $path the request path with the base path already removed
     */
    public function checkForUpdates(Request $request, string $path): void
    {
        $path = (string) strtok($path, '?#');

        if (
            $this->updateChecker === null || $request->method !== 'GET'
            || ($path !== '/admin' && !str_starts_with($path, '/admin/'))
            || str_starts_with($path, '/admin/maintenance/updates/run')
            || MaintenanceFlag::read($this->root) !== null
        ) {
            return;
        }

        try {
            if (!$this->updateChecker->isDue()) {
                return;
            }

            $user = $this->auth->user($request->cookies);

            if ($user !== null && $user->can(Capability::ManageSite)) {
                $this->updateChecker->checkIfDue();
            }
        } catch (Throwable $exception) {
            $this->errors->report($exception);
        }
    }

    /**
     * The anonymous install ping, when an administrator has turned it on and one is due. Like the update check it runs
     * only for an Administrator opening an admin page, so no visitor's request ever waits on it, and a failure is
     * recorded by the service and never shown.
     *
     * @param string $path the request path with the base path already removed
     */
    public function sendInstallPing(Request $request, string $path): void
    {
        $path = (string) strtok($path, '?#');

        if (
            $this->installPing === null || $request->method !== 'GET'
            || ($path !== '/admin' && !str_starts_with($path, '/admin/'))
            || str_starts_with($path, '/admin/maintenance/updates/run')
            || MaintenanceFlag::read($this->root) !== null
        ) {
            return;
        }

        try {
            if (!$this->installPing->isDue()) {
                return;
            }

            $user = $this->auth->user($request->cookies);

            if ($user !== null && $user->can(Capability::ManageSite)) {
                $this->installPing->maybeSendPing();
            }
        } catch (Throwable $exception) {
            $this->errors->report($exception);
        }
    }

    /**
     * The maintenance page, when this visitor is being turned away; null when the request may go on. The admin area is
     * never held, so the administrator can always get in to switch maintenance off or finish an update.
     *
     * @param string $path the request path with the base path already removed
     */
    public function maintenanceResponse(Request $request, string $path): ?Response
    {
        if ($this->maintenance === null) {
            return null;
        }

        $path = (string) strtok($path, '?#');

        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            return null;
        }

        try {
            // A settings table that cannot be read must not lock everyone out, so a failure here lets the request through.
            if (!$this->maintenance->isActive() || $this->maintenance->admits($request->ip(), $this->auth->user($request->cookies))) {
                return null;
            }
        } catch (Throwable $exception) {
            $this->errors->report($exception);

            return null;
        }

        try {
            return $this->themes
                ->render('maintenance', ['message' => $this->maintenance->message()], 503, ['title' => 'Down for maintenance', 'noindex' => true])
                ->withHeader('Retry-After', '3600')
                ->withHeader('Cache-Control', 'no-store');
        } catch (Throwable $exception) {
            $this->errors->report($exception);

            return MaintenanceFlag::page($this->maintenance->message());
        }
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
