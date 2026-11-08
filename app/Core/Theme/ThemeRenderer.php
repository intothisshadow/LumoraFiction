<?php

/**
 * Turns a view name and its data into a page by running the active theme's templates.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Theme;

use Closure;
use InvalidArgumentException;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Errors\ErrorHandler;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Http\BasePath;
use LumoraFiction\Core\Http\PublicUrl;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Services\NewsSettings;
use LumoraFiction\Services\ThemeOptions;
use Throwable;

/**
 * A theme that is missing, unreadable or throws while rendering never takes the site down: the failure is logged and
 * the page is produced again by the built-in theme. The built-in theme is the last resort, so a failure there is
 * left to the error handler.
 */
final class ThemeRenderer
{
    public const DEFAULT_THEME = 'storybook';

    public const NOT_FOUND_MESSAGE = 'The page you are looking for does not exist.';

    /**
     * For each view, the templates tried in order. A theme supplies as many as it likes; index is the only one it needs.
     */
    public const HIERARCHY = [
        'home' => ['home', 'index'],
        'story' => ['story', 'single', 'index'],
        'chapter' => ['chapter', 'single', 'index'],
        'author' => ['author', 'archive', 'index'],
        'archive' => ['archive', 'index'],
        'search' => ['search', 'archive', 'index'],
        'browse' => ['browse', 'archive', 'index'],
        'page' => ['page', 'index'],
        'account' => ['account', 'page', 'index'],
        'bookmarks' => ['bookmarks', 'archive', 'index'],
        'continue' => ['continue', 'bookmarks', 'archive', 'index'],
        'updates' => ['updates', 'bookmarks', 'archive', 'index'],
        // No generic template fits an announcement, so a theme should supply these; without them index.php draws only the title.
        'news-archive' => ['news-archive', 'index'],
        'news-single' => ['news-single', 'index'],
        '404' => ['404', 'index'],
        // Themes that have no maintenance template show the page-not-found one, which prints the same title and message.
        'maintenance' => ['maintenance', '404', 'index'],
    ];

    private const SAME_SITE = '#^/(?![/\\\\])[^\s"\'<>\\\\]*\z#';

    /** @var array<string, true> themes whose functions file has been run in this request */
    private array $booted = [];

    private ?ThemeInfo $rendering = null;

    /** The theme chosen for this request by whoever decides that (a preview, a visitor's choice); null means the site's own. */
    private ?string $requested = null;

    /** @var array<string, string> headers every page of this request carries, such as Vary when pages depend on the visitor */
    private array $extraHeaders = [];

    /** @var (Closure(string): ?array<string, mixed>)|null */
    private ?Closure $themeTools = null;

    public function __construct(
        private readonly ThemeCatalog $catalog,
        private readonly Configuration $config,
        private readonly HookManager $hooks,
        private readonly ThemeOptions $options,
        private readonly ErrorHandler $errors,
        private readonly string $version,
    ) {
    }

    public function catalog(): ThemeCatalog
    {
        return $this->catalog;
    }

    /**
     * The theme the administrator chose, or the default theme when nobody has chosen one, or the built-in theme
     * when that one is gone or no longer valid.
     */
    public function activeTheme(): ThemeInfo
    {
        $slug = $this->config->setting('theme.active', '');
        $slug = is_string($slug) && $slug !== '' ? $slug : self::DEFAULT_THEME;

        return $this->catalog->find($slug) ?? $this->catalog->fallback();
    }

    /**
     * Asks for a theme other than the site's for the rest of this request. Only one place decides which, and the renderer
     * still checks the answer: a name that is missing, not valid or no longer compatible falls back to the site's theme.
     *
     * @param array<string, string> $headers added to every page of this request
     */
    public function useTheme(?string $slug, array $headers = []): void
    {
        $this->requested = $slug;
        $this->extraHeaders = $headers;
    }

    /**
     * What public pages are drawn in for this request: the theme asked for through useTheme() when it is still a good
     * choice, otherwise the site's own. Admin screens never come through here, and they never use this.
     */
    public function currentTheme(): ThemeInfo
    {
        if ($this->requested !== null) {
            $theme = $this->catalog->find($this->requested);

            if ($theme !== null && !$theme->builtIn && $theme->unmetRequirements(PHP_VERSION, $this->version) === []) {
                return $theme;
            }
        }

        return $this->activeTheme();
    }

    /**
     * Lets themes print the theme switcher and the preview banner without knowing where their data comes from.
     *
     * @param (Closure(string): ?array<string, mixed>)|null $tools given "switch" or "banner", returns the part's data or null
     */
    public function setThemeTools(?Closure $tools): void
    {
        $this->themeTools = $tools;
    }

    /**
     * Runs the active theme's functions file once, so its hooks and option declarations are in place. A failure
     * is logged and ignored: the theme's templates may still work, and the admin screens must not depend on it.
     */
    public function bootTheme(?ThemeInfo $theme = null): void
    {
        $theme ??= $this->activeTheme();

        if ($theme->builtIn || isset($this->booted[$theme->slug])) {
            return;
        }

        $this->booted[$theme->slug] = true;
        $file = $theme->path . '/functions.php';

        if (!is_file($file)) {
            return;
        }

        try {
            (static function (string $__file): void {
                require $__file;
            })($file);
        } catch (Throwable $exception) {
            $this->errors->report($exception);
        }
    }

    /**
     * The templates that would be tried for a view, most specific first. A variant names a more specific
     * template for the same view, such as "fandom" for the archive: archive-fandom.php before archive.php.
     *
     * @return list<string>
     */
    public function candidates(string $view, ?string $variant = null): array
    {
        if (!isset(self::HIERARCHY[$view])) {
            throw new InvalidArgumentException('Unknown view.');
        }

        if ($variant !== null && preg_match('/^[a-z][a-z0-9-]*\z/', $variant) !== 1) {
            throw new InvalidArgumentException('Invalid template variant.');
        }

        $names = self::HIERARCHY[$view];

        return $variant === null ? $names : [$view . '-' . $variant, ...$names];
    }

    /**
     * The first template the theme provides for a view, or null when it provides none of them.
     */
    public function resolve(string $view, ?string $variant = null, ?ThemeInfo $theme = null): ?string
    {
        $theme ??= $this->activeTheme();

        foreach ($this->candidates($view, $variant) as $name) {
            $file = $theme->path . '/' . $name . '.php';

            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data the view data: plain values, lists, and SafeHtml for sanitised markup
     * @param array{title?: string, noindex?: bool, preview?: array{label: string, edit_url: string}|null, variant?: string|null} $page
     */
    public function render(string $view, array $data = [], int $status = 200, array $page = []): Response
    {
        $theme = $this->currentTheme();

        try {
            $html = $this->renderWith($theme, $view, $data, $page);
        } catch (Throwable $exception) {
            $this->errors->report($exception);

            if ($theme->builtIn) {
                throw $exception;
            }

            $html = $this->renderWith($this->catalog->fallback(), $view, $data, $page);
        }

        $headers = (($page['noindex'] ?? false) ? ['X-Robots-Tag' => 'noindex, nofollow'] : []) + $this->extraHeaders;

        return Response::html($html, $status, $headers);
    }

    /**
     * The page for an address that leads nowhere. A story that is not public is answered with exactly this page, so
     * a visitor cannot tell it from one that never existed.
     */
    public function notFound(): Response
    {
        return $this->render('404', ['message' => self::NOT_FOUND_MESSAGE], 404, ['title' => 'Page not found', 'noindex' => true]);
    }

    /**
     * Prints a template part such as the header, from the theme being rendered, or from the built-in theme when
     * that theme does not have one.
     *
     * @param array<string, mixed> $data
     */
    public function includePart(string $name, array $data = []): void
    {
        if (preg_match('/^[a-z][a-z0-9-]*\z/', $name) !== 1 || $this->rendering === null) {
            return;
        }

        $relative = in_array($name, ['header', 'footer', 'sidebar'], true) ? $name . '.php' : 'parts/' . $name . '.php';

        foreach ([$this->rendering, $this->catalog->fallback()] as $theme) {
            $file = $theme->path . '/' . $relative;

            if (is_file($file)) {
                self::include($file, $data);

                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array{title?: string, noindex?: bool, preview?: array{label: string, edit_url: string}|null, variant?: string|null} $page
     */
    private function renderWith(ThemeInfo $theme, string $view, array $data, array $page): string
    {
        $this->bootTheme($theme);

        $file = $this->resolve($view, $page['variant'] ?? null, $theme) ?? $this->resolve($view, null, $this->catalog->fallback());

        if ($file === null) {
            throw new InvalidArgumentException('No template for this view.');
        }

        $css = $this->options->css($theme->slug);
        $context = new ThemeContext(
            $view,
            $data,
            $page['title'] ?? '',
            $page['noindex'] ?? false,
            $page['preview'] ?? null,
            $theme,
            (string) $this->config->setting('site.name', 'Lumora Fiction'),
            $this->version,
            $this->options->values($theme->slug),
            // Named by theme as well as by content, so one theme's options are never served under another's address.
            $css === '' ? '' : BasePath::get() . '/theme-options.css?theme=' . rawurlencode($theme->slug) . '&v=' . substr(md5($css), 0, 10),
            $this->navigation($view),
            $this->hooks,
            $this->includePart(...),
            $this->footerLinks(),
            fn (): ?array => $this->privacyLink(),
            $this->themeTools,
        );

        $level = ob_get_level();
        $previousContext = ThemeContext::activate($context);
        $previousTheme = $this->rendering;
        $this->rendering = $theme;

        ob_start();

        try {
            self::include($file, $data);

            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            // Whatever the broken template had printed so far is thrown away with it.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $exception;
        } finally {
            $this->rendering = $previousTheme;
            ThemeContext::activate($previousContext);
        }
    }

    /**
     * The main menu, which features extend through the lf_navigation_items filter. Addresses must be paths on this site.
     *
     * @return list<array{label: string, url: string, current: bool}>
     */
    private function navigation(string $view): array
    {
        $home = BasePath::get() . '/';
        $menu = [
            ['label' => 'Home', 'url' => $home, 'current' => $view === 'home'],
            ['label' => 'Browse', 'url' => BasePath::get() . PublicUrl::BROWSE, 'current' => $view === 'browse'],
            ['label' => 'Search', 'url' => BasePath::get() . PublicUrl::SEARCH, 'current' => $view === 'search'],
        ];

        // Switching news off takes the link away with the pages, so no menu entry leads to a not-found page.
        if (NewsSettings::enabled($this->config)) {
            $menu[] = ['label' => 'News', 'url' => PublicUrl::news(), 'current' => in_array($view, ['news-archive', 'news-single'], true)];
        }

        return $this->cleanLinks($this->hooks->applyFilters('lf_navigation_items', $menu));
    }

    /**
     * The footer link list, which features extend through the lf_footer_items filter. Empty unless something adds to it.
     *
     * @return list<array{label: string, url: string, current: bool}>
     */
    private function footerLinks(): array
    {
        return $this->cleanLinks($this->hooks->applyFilters('lf_footer_items', []));
    }

    /**
     * The privacy policy link, which core supplies through the lf_privacy_policy filter once a page is named. Only a
     * path on this site is accepted, like every other link a filter can add.
     *
     * @return array{label: string, url: string}|null
     */
    private function privacyLink(): ?array
    {
        $link = $this->hooks->applyFilters('lf_privacy_policy', null);

        return is_array($link) && is_string($link['label'] ?? null) && $link['label'] !== '' && is_string($link['url'] ?? null) && preg_match(self::SAME_SITE, $link['url']) === 1
            ? ['label' => $link['label'], 'url' => $link['url']]
            : null;
    }

    /**
     * Keeps only well-formed entries whose address is a path on this site, so a filter cannot put an outside link in a menu.
     *
     * @return list<array{label: string, url: string, current: bool}>
     */
    private function cleanLinks(mixed $items): array
    {
        $clean = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && is_string($item['label'] ?? null) && $item['label'] !== '' && is_string($item['url'] ?? null) && preg_match(self::SAME_SITE, $item['url']) === 1) {
                $clean[] = ['label' => $item['label'], 'url' => $item['url'], 'current' => ($item['current'] ?? false) === true];
            }
        }

        return $clean;
    }

    /**
     * Runs a template in a scope of its own that holds only the view data. Keys that are not plain variable names are skipped.
     *
     * @param array<string, mixed> $data
     */
    private static function include(string $file, array $data): void
    {
        $data = array_filter($data, static fn (mixed $key): bool => is_string($key) && preg_match('/^[a-z][A-Za-z0-9_]*\z/', $key) === 1, ARRAY_FILTER_USE_KEY);

        (static function (string $__file, array $__data): void {
            extract($__data, EXTR_SKIP);

            require $__file;
        })($file, $data);
    }
}
