<?php

/**
 * Template tags: the functions a theme calls to print a page. Every tag that prints escapes what it prints.
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

use LumoraFiction\Core\Theme\ThemeContext;
use LumoraFiction\Helpers\Html;

// Each tag is a thin wrapper over the current ThemeContext, so the logic stays in one tested class. Called with
// no page being rendered (a theme file requested directly, say) every tag does nothing.

// ---- page structure ----------------------------------------------------------------------------------------

if (!function_exists('lf_head')) {
    /** Prints everything that belongs in the head element: charset, viewport, title, stylesheets and head hooks. */
    function lf_head(): void
    {
        ThemeContext::current()?->head();
    }
}

if (!function_exists('lf_body_open')) {
    /** Prints what belongs right after the opening body tag, such as the preview notice. */
    function lf_body_open(): void
    {
        ThemeContext::current()?->bodyOpen();
    }
}

if (!function_exists('lf_footer')) {
    /** Prints the scripts and footer hooks. Call it just before the closing body tag. */
    function lf_footer(): void
    {
        ThemeContext::current()?->footer();
    }
}

if (!function_exists('lf_body_class')) {
    /** Prints the classes for the body element. */
    function lf_body_class(): void
    {
        echo ThemeContext::current()?->bodyClass() ?? '';
    }
}

if (!function_exists('lf_get_header')) {
    /** Includes header.php from the theme, or the built-in header when the theme has none. */
    function lf_get_header(): void
    {
        ThemeContext::current()?->part('header');
    }
}

if (!function_exists('lf_get_footer')) {
    function lf_get_footer(): void
    {
        ThemeContext::current()?->part('footer');
    }
}

if (!function_exists('lf_get_sidebar')) {
    function lf_get_sidebar(): void
    {
        ThemeContext::current()?->part('sidebar');
    }
}

if (!function_exists('lf_part')) {
    /**
     * Includes parts/{name}.php from the theme. The data is passed as one array and its keys become the variables of the part.
     *
     * @param array<string, mixed> $data
     */
    function lf_part(string $name, array $data = []): void
    {
        ThemeContext::current()?->part($name, $data);
    }
}

if (!function_exists('lf_downloads')) {
    /**
     * Prints the story page's download menu through the theme's downloads part, or nothing when the story cannot be
     * downloaded. Whether it can is decided in core, so a theme only draws it.
     */
    function lf_downloads(): void
    {
        ThemeContext::current()?->part('downloads');
    }
}

if (!function_exists('lf_navigation')) {
    /** Prints the main menu as a list of links. */
    function lf_navigation(string $label = 'Main'): void
    {
        ThemeContext::current()?->navigation($label);
    }
}

if (!function_exists('lf_pagination')) {
    /** Prints previous and next links with page numbers, when the page has more than one page of results. */
    function lf_pagination(): void
    {
        ThemeContext::current()?->pagination();
    }
}

// ---- site facts and addresses -------------------------------------------------------------------------------

if (!function_exists('lf_site_name')) {
    function lf_site_name(): void
    {
        echo Html::e(ThemeContext::current()?->siteName() ?? '');
    }
}

if (!function_exists('lf_title')) {
    /** Prints the title of the page being shown, without the site name. */
    function lf_title(): void
    {
        echo Html::e(ThemeContext::current()?->pageTitle() ?? '');
    }
}

if (!function_exists('lf_site_url')) {
    /** Prints an address on this site, for example lf_site_url('search'). */
    function lf_site_url(string $path = ''): void
    {
        echo Html::e(ThemeContext::current()?->siteUrl($path) ?? '');
    }
}

if (!function_exists('lf_news_url')) {
    /** Prints the address of the News page, which answers only while news is switched on. */
    function lf_news_url(): void
    {
        echo Html::e(ThemeContext::current()?->siteUrl('news') ?? '');
    }
}

if (!function_exists('lf_theme_url')) {
    /** Prints the address of a file in the active theme's folder. */
    function lf_theme_url(string $path = ''): void
    {
        echo Html::e(ThemeContext::current()?->themeUrl($path) ?? '');
    }
}

if (!function_exists('lf_asset_url')) {
    /** Prints the address of a file in the shared assets folder. */
    function lf_asset_url(string $path): void
    {
        echo Html::e(ThemeContext::current()?->assetUrl($path) ?? '');
    }
}

if (!function_exists('lf_is')) {
    /** Whether the page being shown is the given view: home, story, chapter, author, archive, search, page or 404. */
    function lf_is(string $view): bool
    {
        return ThemeContext::current()?->isView($view) ?? false;
    }
}

// ---- the page's data ----------------------------------------------------------------------------------------

if (!function_exists('lf_field')) {
    /** Prints one value from the page's data by its dotted name, such as lf_field('story.title'). */
    function lf_field(string $path): void
    {
        echo ThemeContext::current()?->field($path) ?? '';
    }
}

if (!function_exists('lf_value')) {
    /** The same value as lf_field(), returned instead of printed, already escaped for use inside an attribute. */
    function lf_value(string $path): string
    {
        return ThemeContext::current()?->field($path) ?? '';
    }
}

if (!function_exists('lf_has')) {
    /** Whether the page's data has a non-empty value at the dotted name. */
    function lf_has(string $path): bool
    {
        return ThemeContext::current()?->has($path) ?? false;
    }
}

if (!function_exists('lf_items')) {
    /**
     * The entries of a list in the page's data, to loop over. The values inside are not escaped: print them with lf_e().
     *
     * @return list<array<string, mixed>>
     */
    function lf_items(string $path): array
    {
        return ThemeContext::current()?->items($path) ?? [];
    }
}

if (!function_exists('lf_term_links')) {
    /** Prints a list of {name, url} entries as links, for example lf_term_links('terms.fandom'). */
    function lf_term_links(string $path, string $separator = ', '): void
    {
        ThemeContext::current()?->termLinks($path, $separator);
    }
}

if (!function_exists('lf_e')) {
    /** Escapes a value for printing in HTML text or in a quoted attribute. */
    function lf_e(mixed $value): string
    {
        return ThemeContext::printable($value);
    }
}

// ---- theme options -----------------------------------------------------------------------------------------

if (!function_exists('lf_option')) {
    /** Prints the value of one of the active theme's options. */
    function lf_option(string $key): void
    {
        echo ThemeContext::current()?->option($key) ?? '';
    }
}

if (!function_exists('lf_option_enabled')) {
    /** Whether a checkbox option of the active theme is on. */
    function lf_option_enabled(string $key): bool
    {
        return ThemeContext::current()?->optionEnabled($key) ?? false;
    }
}
