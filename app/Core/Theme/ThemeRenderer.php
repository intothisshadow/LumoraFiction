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

use InvalidArgumentException;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Errors\ErrorHandler;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Http\BasePath;
use LumoraFiction\Core\Http\Response;
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
        'page' => ['page', 'index'],
        'account' => ['account', 'page', 'index'],
        '404' => ['404', 'index'],
    ];

    private const SAME_SITE = '#^/(?![/\\\\])[^\s"\'<>\\\\]*\z#';

    /** @var array<string, true> themes whose functions file has been run in this request */
    private array $booted = [];

    private ?ThemeInfo $rendering = null;

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
        $theme = $this->activeTheme();

        try {
            $html = $this->renderWith($theme, $view, $data, $page);
        } catch (Throwable $exception) {
            $this->errors->report($exception);

            if ($theme->builtIn) {
                throw $exception;
            }

            $html = $this->renderWith($this->catalog->fallback(), $view, $data, $page);
        }

        $headers = ($page['noindex'] ?? false) ? ['X-Robots-Tag' => 'noindex, nofollow'] : [];

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
            $css === '' ? '' : BasePath::get() . '/theme-options.css?v=' . substr(md5($css), 0, 10),
            $this->navigation($view),
            $this->hooks,
            $this->includePart(...),
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
        $items = $this->hooks->applyFilters('lf_navigation_items', [['label' => 'Home', 'url' => $home, 'current' => $view === 'home']]);
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
