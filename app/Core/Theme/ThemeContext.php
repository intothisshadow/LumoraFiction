<?php

/**
 * What the template tags can see while a page is rendered: the view data for the page, the theme, and a few site facts.
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
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Http\BasePath;
use LumoraFiction\Helpers\Html;

/**
 * This is the whole surface a theme reaches through the template tags. It holds no configuration, no database
 * and no request, only values the controller chose to hand over, so a template cannot read anything it was not
 * given. Everything printed here is escaped unless the value is wrapped in SafeHtml. PHP cannot sandbox a theme
 * that deliberately reaches around the tags; this is a boundary for honest themes, not a defence against a hostile one.
 */
final class ThemeContext
{
    /** A path on this site: one slash, then anything that is not a second slash, a backslash or markup. */
    private const SAME_SITE = '#^/(?![/\\\\])[^\s"\'<>\\\\]*\z#';

    private static ?self $current = null;

    /**
     * @param array<string, mixed> $data
     * @param array{label: string, edit_url: string}|null $preview
     * @param array<string, string|bool> $options
     * @param list<array{label: string, url: string, current: bool}> $navigation
     * @param Closure(string, array<string, mixed>): void $includePart
     */
    public function __construct(
        public readonly string $view,
        private readonly array $data,
        private readonly string $title,
        private readonly bool $noindex,
        private readonly ?array $preview,
        private readonly ThemeInfo $theme,
        private readonly string $siteName,
        private readonly string $assetVersion,
        private readonly array $options,
        private readonly string $optionsCssUrl,
        private readonly array $navigation,
        private readonly HookManager $hooks,
        private readonly Closure $includePart,
        private readonly array $footerLinks = [],
        private readonly ?Closure $privacyLink = null,
        private readonly ?Closure $themeTools = null,
        private readonly ?Closure $feedLink = null,
    ) {
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * Makes a context the current one and returns the one it replaced, so a renderer can put that back afterwards.
     */
    public static function activate(?self $context): ?self
    {
        $previous = self::$current;
        self::$current = $context;

        return $previous;
    }

    // ---- reading the view data --------------------------------------------------------------------

    public function value(string $path): mixed
    {
        $value = $this->data;

        foreach (explode('.', $path) as $segment) {
            if (preg_match('/^[A-Za-z0-9_]+\z/', $segment) !== 1 || !is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * The value as markup that is safe to print: escaped text, or the markup of a SafeHtml.
     */
    public function field(string $path): string
    {
        return self::printable($this->value($path));
    }

    public function has(string $path): bool
    {
        $value = $this->value($path);

        return $value instanceof SafeHtml ? $value->html !== '' : !in_array($value, [null, '', [], false], true);
    }

    /**
     * The entries of a list in the view data, for a template to loop over; anything that is not a list of arrays gives an empty list.
     *
     * @return list<array<string, mixed>>
     */
    public function items(string $path): array
    {
        $value = $this->value($path);

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    public static function printable(mixed $value): string
    {
        return match (true) {
            $value instanceof SafeHtml => $value->html,
            is_string($value) => Html::e($value),
            is_int($value), is_float($value) => Html::e((string) $value),
            $value === true => '1',
            default => '',
        };
    }

    // ---- addresses ------------------------------------------------------------------------------------

    public function siteUrl(string $path = ''): string
    {
        $path = ltrim($path, '/');

        // Anything that could change which site the address points at is dropped rather than encoded.
        if ($path !== '' && (preg_match('#^[A-Za-z0-9_./?=&%+~:-]*\z#', $path) !== 1 || str_contains($path, '..'))) {
            $path = '';
        }

        return BasePath::get() . '/' . $path;
    }

    /**
     * A file shipped with the active theme. The built-in theme has none, so it answers with an empty string.
     */
    public function themeUrl(string $path = ''): string
    {
        $path = ltrim($path, '/');

        if ($this->theme->builtIn || ($path !== '' && (preg_match('#^[A-Za-z0-9_./-]+\z#', $path) !== 1 || str_contains($path, '..')))) {
            return '';
        }

        return BasePath::get() . '/content/themes/' . $this->theme->slug . '/' . $path;
    }

    /**
     * A file in the top-level assets folder that every theme shares.
     */
    public function assetUrl(string $path): string
    {
        $path = ltrim($path, '/');

        if ($path === '' || preg_match('#^[A-Za-z0-9_./-]+\z#', $path) !== 1 || str_contains($path, '..')) {
            return '';
        }

        return BasePath::get() . '/assets/' . $path . '?v=' . rawurlencode($this->assetVersion);
    }

    // ---- options and state --------------------------------------------------------------------------

    public function option(string $key): string
    {
        return self::printable($this->options[$key] ?? null);
    }

    public function optionEnabled(string $key): bool
    {
        return ($this->options[$key] ?? false) === true;
    }

    public function isView(string $view): bool
    {
        return $this->view === $view;
    }

    public function pageTitle(): string
    {
        return $this->title;
    }

    public function siteName(): string
    {
        return $this->siteName;
    }

    // ---- printing ---------------------------------------------------------------------------------------

    public function documentTitle(): string
    {
        $title = $this->title === '' ? $this->siteName : $this->title . ' — ' . $this->siteName;
        $filtered = $this->hooks->applyFilters('lf_document_title', $title, $this->view);

        return is_string($filtered) && $filtered !== '' ? $filtered : $title;
    }

    /**
     * Everything that belongs inside the head element. A theme writes one call and never lists its own stylesheet
     * or the scripts a feature needs, so a feature can add its files in one place for every theme.
     */
    public function head(): void
    {
        echo '<meta charset="utf-8">', "\n";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">', "\n";
        echo '<title>', Html::e($this->documentTitle()), '</title>', "\n";

        // The page can ask for it, and so can the site as a whole (the setting that discourages search engines).
        if ($this->noindex || $this->hooks->applyFilters('lf_noindex', false, $this->view) === true) {
            echo '<meta name="robots" content="noindex, nofollow">', "\n";
        }

        $stylesheet = $this->theme->builtIn
            ? BasePath::get() . '/theme-fallback.css?v=' . rawurlencode($this->assetVersion)
            : $this->themeUrl('style.css') . '?v=' . rawurlencode($this->theme->version);

        echo '<link rel="stylesheet" href="', Html::e($stylesheet), '">', "\n";

        foreach ($this->queued('lf_styles') as $item) {
            echo '<link rel="stylesheet" href="', Html::e($item), '">', "\n";
        }

        if ($this->optionsCssUrl !== '') {
            echo '<link rel="stylesheet" href="', Html::e($this->optionsCssUrl), '">', "\n";
        }

        $this->hooks->doAction('lf_head', $this->view);
    }

    public function bodyOpen(): void
    {
        if ($this->preview !== null) {
            $edit = preg_match(self::SAME_SITE, $this->preview['edit_url']) === 1
                ? ' <a href="' . Html::e($this->preview['edit_url']) . '">Back to editing</a>'
                : '';

            echo '<p class="lf-notice lf-notice--info lf-preview-banner" role="status">This is a preview. Only you and the site staff can see it, and it is ',
                Html::e($this->preview['label']), '.', $edit, '</p>', "\n";
        }

        $this->hooks->doAction('lf_body_open', $this->view);
    }

    public function footer(): void
    {
        foreach ($this->queued('lf_scripts') as $item) {
            echo '<script src="', Html::e($item), '" defer></script>', "\n";
        }

        $this->hooks->doAction('lf_footer', $this->view);
    }

    public function bodyClass(): string
    {
        $classes = ['lf-view-' . $this->view];

        if ($this->preview !== null) {
            $classes[] = 'lf-is-preview';
        }

        $filtered = $this->hooks->applyFilters('lf_body_class', $classes, $this->view);

        return Html::e(implode(' ', array_filter(
            is_array($filtered) ? $filtered : $classes,
            static fn (mixed $class): bool => is_string($class) && preg_match('/^[A-Za-z][A-Za-z0-9_-]*\z/', $class) === 1,
        )));
    }

    public function navigation(string $label = 'Main'): void
    {
        if ($this->navigation === []) {
            return;
        }

        echo '<nav class="lf-nav" aria-label="', Html::e($label), '"><ul class="lf-nav__list">';

        foreach ($this->navigation as $item) {
            echo '<li class="lf-nav__item', $item['current'] ? ' lf-nav__item--current' : '', '"><a class="lf-nav__link" href="', Html::e($item['url']), '"',
                $item['current'] ? ' aria-current="page"' : '', '>', Html::e($item['label']), '</a></li>';
        }

        echo '</ul></nav>', "\n";
    }

    /**
     * The footer links (static pages an administrator placed there, and anything a plugin adds) as a list. Prints
     * nothing when there are none, so a theme can call it unconditionally.
     */
    private static function feedIcon(string $class): string
    {
        return '<svg class="' . $class . '" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><circle cx="3" cy="13" r="1.75" fill="currentColor"/><path d="M1.5 7.25a7.25 7.25 0 0 1 7.25 7.25M1.5 1.75a12.75 12.75 0 0 1 12.75 12.75" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>';
    }

    public function footerLinks(string $label = 'Footer'): void
    {
        if ($this->footerLinks === []) {
            return;
        }

        echo '<nav class="lf-footer-links" aria-label="', Html::e($label), '"><ul class="lf-footer-links__list">';

        foreach ($this->footerLinks as $item) {
            echo '<li class="lf-footer-links__item', $item['current'] ? ' lf-footer-links__item--current' : '', '"><a class="lf-footer-links__link" href="', Html::e($item['url']), '"',
                $item['current'] ? ' aria-current="page"' : '', '>', ($item['icon'] ?? '') === 'feed' ? self::feedIcon('lf-footer-links__icon') : '', Html::e($item['label']), '</a></li>';
        }

        echo '</ul></nav>', "\n";
    }

    /**
     * The theme switcher, through the theme's own part, or nothing at all while visitors may not choose a theme or only
     * one is offered. The form, its token and the list of themes come from core, so a theme only places it.
     */
    public function themeSwitch(): void
    {
        $data = $this->themeTools === null ? null : ($this->themeTools)('switch');

        if (is_array($data) && $data !== []) {
            $this->part('theme-switch', ['switch' => $data]);
        }
    }

    /**
     * The notice shown while an Administrator is previewing a theme, with its two buttons, or nothing otherwise.
     */
    public function themePreviewBanner(): void
    {
        $data = $this->themeTools === null ? null : ($this->themeTools)('banner');

        if (is_array($data) && $data !== []) {
            $this->part('theme-preview-banner', ['banner' => $data]);
        }
    }

    /**
     * A paragraph linking to the privacy policy, such as "Please read our Privacy Policy.", or nothing at all while
     * the administrator has not named a published page. The text around the link is the theme's own; the link is not.
     */
    public function privacyLink(string $before = '', string $after = ''): void
    {
        $link = $this->privacyLink === null ? null : ($this->privacyLink)();

        if (!is_array($link) || !is_string($link['label'] ?? null) || !is_string($link['url'] ?? null)) {
            return;
        }

        echo '<p class="lf-privacy-link">', Html::e($before), '<a class="lf-privacy-link__anchor" href="', Html::e($link['url']), '">', Html::e($link['label']), '</a>', Html::e($after), '</p>', "\n";
    }

    /**
     * A link to the feed of the page being shown (an author's stories, a fandom, a story's chapters), or nothing when
     * the page has no feed on offer. The address and the words come from core, so a theme only places it.
     */
    public function feedLink(string $before = '', string $after = ''): void
    {
        $link = $this->feedLink === null ? null : ($this->feedLink)();

        if (!is_array($link) || !is_string($link['label'] ?? null) || !is_string($link['url'] ?? null)) {
            return;
        }

        echo '<p class="lf-feed-link">', Html::e($before), '<a class="lf-feed-link__anchor" href="', Html::e($link['url']), '" rel="alternate" type="application/rss+xml">',
            self::feedIcon('lf-feed-link__icon'),
            '<span class="lf-feed-link__text">Feed for ', Html::e($link['label']), '</span></a>', Html::e($after), '</p>', "\n";
    }

    /**
     * Previous and next links with a window of page numbers, from the "pagination" entry of the view data:
     * the current page, the number of pages, and an address with a {page} marker.
     */
    public function pagination(): void
    {
        $page = $this->value('pagination.page');
        $pages = $this->value('pagination.pages');
        $pattern = $this->value('pagination.url');

        if (!is_int($page) || !is_int($pages) || !is_string($pattern) || $pages < 2 || preg_match(self::SAME_SITE, $pattern) !== 1 || !str_contains($pattern, '{page}')) {
            return;
        }

        $page = max(1, min($pages, $page));
        $link = static fn (int $number): string => Html::e(str_replace('{page}', (string) $number, $pattern));
        $item = static fn (string $inner, string $modifier = ''): string => '<li class="lf-pagination__item' . $modifier . '">' . $inner . '</li>';

        echo '<nav class="lf-pagination" aria-label="Pages"><ul class="lf-pagination__list">';

        if ($page > 1) {
            echo $item('<a class="lf-pagination__link" href="' . $link($page - 1) . '" rel="prev">Previous</a>', ' lf-pagination__item--prev');
        }

        $last = 0;

        for ($number = 1; $number <= $pages; $number++) {
            if ($number !== 1 && $number !== $pages && abs($number - $page) > 2) {
                continue;
            }

            if ($number - $last > 1) {
                echo $item('&hellip;', ' lf-pagination__item--gap');
            }

            echo $number === $page
                ? $item('<a class="lf-pagination__link" href="' . $link($number) . '" aria-current="page">' . $number . '</a>', ' lf-pagination__item--current')
                : $item('<a class="lf-pagination__link" href="' . $link($number) . '">' . $number . '</a>');

            $last = $number;
        }

        if ($page < $pages) {
            echo $item('<a class="lf-pagination__link" href="' . $link($page + 1) . '" rel="next">Next</a>', ' lf-pagination__item--next');
        }

        echo '</ul></nav>', "\n";
    }

    /**
     * A list of {name, url} entries as links; an entry without an address is printed as plain text.
     */
    public function termLinks(string $path, string $separator = ', '): void
    {
        $parts = [];

        foreach ($this->items($path) as $term) {
            $name = is_string($term['name'] ?? null) ? $term['name'] : '';
            $url = is_string($term['url'] ?? null) ? $term['url'] : '';

            if ($name === '') {
                continue;
            }

            $parts[] = preg_match(self::SAME_SITE, $url) === 1
                ? '<a class="lf-term-link" href="' . Html::e($url) . '">' . Html::e($name) . '</a>'
                : '<span class="lf-term-link">' . Html::e($name) . '</span>';
        }

        echo implode(Html::e($separator), $parts);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function part(string $name, array $data = []): void
    {
        ($this->includePart)($name, $data);
    }

    /**
     * Addresses that features add through the lf_styles and lf_scripts filters. Only paths on this site are
     * kept, so the Content-Security-Policy keeps meaning what it says.
     *
     * @return list<string>
     */
    private function queued(string $filter): array
    {
        $queued = $this->hooks->applyFilters($filter, []);
        $urls = [];

        foreach (is_array($queued) ? $queued : [] as $handle => $url) {
            if (is_string($handle) && preg_match('/^[a-z][a-z0-9_-]*\z/', $handle) === 1 && is_string($url) && preg_match(self::SAME_SITE, $url) === 1) {
                $urls[$handle] = $url;
            }
        }

        return array_values($urls);
    }
}
