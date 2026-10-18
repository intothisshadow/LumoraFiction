<?php

/**
 * The two stylesheets the theme layer generates, which are not part of the archive itself.
 *
 * @package LumoraFiction
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Controllers;

use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Services\ThemeOptions;

final class PublicController
{
    public function __construct(
        private readonly ThemeRenderer $themes,
        private readonly ThemeOptions $options,
        private readonly string $fallbackStylesheet,
    ) {
    }

    /**
     * The stylesheet made from the active theme's options. It is a file of its own rather than a style element
     * because the Content-Security-Policy allows no inline styles. It is empty until an option is changed.
     *
     * @param array<string, string> $params
     */
    public function themeOptionsStylesheet(array $params, Request $request): Response
    {
        $theme = $this->themes->activeTheme();

        // The options a theme offers are declared in its functions file, so that has to run first.
        $this->themes->bootTheme($theme);

        return $this->stylesheet($this->options->css($theme->slug), 'public, max-age=300');
    }

    /**
     * @param array<string, string> $params
     */
    public function fallbackStylesheet(array $params, Request $request): Response
    {
        $css = is_file($this->fallbackStylesheet) ? file_get_contents($this->fallbackStylesheet) : '';

        return $this->stylesheet($css === false ? '' : $css, 'public, max-age=3600');
    }

    private function stylesheet(string $css, string $cacheControl): Response
    {
        return new Response($css, 200, ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => $cacheControl, 'X-Content-Type-Options' => 'nosniff']);
    }
}
