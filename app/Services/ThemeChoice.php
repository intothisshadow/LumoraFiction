<?php

/**
 * Decides which theme a public page is drawn in: an Administrator's preview, then a visitor's own choice, then the site's theme.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.3.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Http\BasePath;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Core\Security\SessionManager;
use LumoraFiction\Core\Theme\ThemeCatalog;
use LumoraFiction\Core\Theme\ThemeInfo;
use LumoraFiction\Core\Theme\ThemeRenderer;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use Throwable;

/**
 * This is the one place the choice is made, so the preview, a visitor's own theme and the site's theme can never be
 * decided in three separate spots. The answer is handed to the renderer, which checks it again. A name that is not a plain
 * folder name, is not installed, no longer fits the site or is not on the offered list never gets as far as the
 * filesystem: it is ignored and the site's theme is used.
 *
 * Admin screens, the installer, downloads, feeds and the stylesheets never ask: they always use the site's theme or none.
 */
final class ThemeChoice
{
    public const DEFAULTS = ['theme.visitor_choice' => false, 'theme.visitor_themes' => []];

    public const PREVIEW_KEY = '_lf_theme_preview';

    public const COOKIE = 'lf_theme';

    public const PREVIEW_TOKEN = 'theme_preview';

    private const COOKIE_SECONDS = 31536000;

    /** Beginnings of addresses whose output is never drawn in a visitor's or an Administrator's chosen theme. */
    private const EXCLUDED = ['/admin', '/install', '/theme-options.css', '/theme-fallback.css', '/feed', '/feeds', '/rss', '/sitemap', '/theme'];

    private ?string $previewing = null;

    private ?string $visitorSlug = null;

    private bool $applies = false;

    private string $returnTo = '/';

    private string $ip = '0.0.0.0';

    public function __construct(
        private readonly ThemeCatalog $catalog,
        private readonly ThemeRenderer $renderer,
        private readonly Configuration $config,
        private readonly AuthService $auth,
        private readonly SessionManager $sessions,
        private readonly ReaderPreferences $preferences,
        private readonly AuditLogService $audit,
        private readonly string $appVersion,
        private readonly string $secretKey,
    ) {
    }

    // ---- the settings ------------------------------------------------------------------------------------------------

    /** Off until an Administrator turns it on, so a site behaves exactly as before. */
    public function enabled(): bool
    {
        return $this->config->setting('theme.visitor_choice', false) === true;
    }

    /**
     * The folder names an Administrator ticked, as stored. Whether each still exists is checked when they are used.
     *
     * @return list<string>
     */
    public function ticked(): array
    {
        $stored = $this->config->setting('theme.visitor_themes', []);

        return is_array($stored) ? array_values(array_filter($stored, static fn (mixed $slug): bool => is_string($slug) && ThemeCatalog::isValidSlug($slug))) : [];
    }

    /**
     * What a visitor may pick: the site's own theme, always, and every ticked theme that is still installed and fits the site.
     *
     * @return array<string, ThemeInfo> keyed by folder name, the site's theme first
     */
    public function offered(): array
    {
        $site = $this->renderer->activeTheme();
        $offered = [$site->slug => $site];

        foreach ($this->ticked() as $slug) {
            $theme = $this->catalog->find($slug);

            if ($theme !== null && !$theme->builtIn && $this->usable($theme)) {
                $offered[$slug] = $theme;
            }
        }

        return $offered;
    }

    /**
     * @param list<string> $slugs the themes ticked on the form; anything that is not an installed theme is dropped
     */
    public function save(bool $enabled, array $slugs, User $actor, string $ipAddress): bool
    {
        if (!$actor->can(Capability::ManageSite)) {
            return false;
        }

        $clean = [];

        foreach (array_unique($slugs) as $slug) {
            $theme = ThemeCatalog::isValidSlug($slug) ? $this->catalog->find($slug) : null;

            if ($theme !== null && !$theme->builtIn) {
                $clean[] = $slug;
            }
        }

        $before = ['enabled' => $this->enabled(), 'themes' => $this->ticked()];

        if ($before['enabled'] === $enabled && $before['themes'] === $clean) {
            return false;
        }

        $this->config->setSetting('theme.visitor_choice', $enabled);
        $this->config->setSetting('theme.visitor_themes', $clean);
        $this->audit->record($actor->id, 'settings.theme.visitor_choice', 'theme', null, ['from' => $before, 'to' => ['enabled' => $enabled, 'themes' => $clean]], $ipAddress);

        return true;
    }

    // ---- one request ----------------------------------------------------------------------------------------------------

    /**
     * Decides the theme for this request and tells the renderer. Returns a redirect only when an Administrator has just
     * started a preview with ?theme=, so the name leaves the address after the first request.
     *
     * @param string $path the request path with the base path and query string removed
     */
    public function begin(Request $request, string $path): ?Response
    {
        $this->renderer->useTheme(null);
        $this->previewing = null;
        $this->visitorSlug = null;
        $this->applies = false;
        $this->ip = $request->ip();
        $this->returnTo = $this->returnPath($request, $path);

        if (!$this->appliesTo($path)) {
            return null;
        }

        $this->applies = true;
        $user = $this->signedIn($request);

        if ($user !== null && $user->can(Capability::ManageSite)) {
            $named = $request->queryValue('theme');

            if ($named !== null && $this->previewable($named)) {
                $this->rememberPreview($named);

                // A link someone follows to start a preview lands on the same page without the name in its address.
                if ($request->method === 'GET') {
                    return Response::redirect($this->returnTo, 302)->withHeader('Cache-Control', 'private, no-store');
                }
            }

            $stored = $this->storedPreview();

            if ($stored !== null) {
                if ($this->previewable($stored)) {
                    $this->previewing = $stored;
                    // Never from a shared cache: it is one person's view, not the page.
                    $this->renderer->useTheme($stored, ['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie']);

                    return null;
                }

                $this->clearPreview();
            }
        }

        if ($this->enabled()) {
            $slug = $this->chosenBy($request, $user);
            $offered = $this->offered();

            if ($slug !== null && isset($offered[$slug]) && $slug !== $this->renderer->activeTheme()->slug) {
                $this->visitorSlug = $slug;
            }

            // Whether a visitor chose or not, the page may differ between visitors, so a shared cache must not mix them up.
            $this->renderer->useTheme($this->visitorSlug, ['Vary' => 'Cookie']);
        }

        return null;
    }

    /**
     * The theme a person asked for before their request is checked against the offered list: their account's, else the cookie.
     */
    private function chosenBy(Request $request, ?User $user): ?string
    {
        if ($user !== null) {
            return $this->preferences->theme($user->id);
        }

        $cookie = $request->cookies[self::COOKIE] ?? null;

        return is_string($cookie) && ThemeCatalog::isValidSlug($cookie) ? $cookie : null;
    }

    private function signedIn(Request $request): ?User
    {
        if (!isset($request->cookies[$this->sessions->name()])) {
            return null;
        }

        try {
            return $this->auth->user($request->cookies);
        } catch (Throwable) {
            return null;
        }
    }

    /** Whether a public page at this address may be drawn in a chosen theme. */
    private function appliesTo(string $path): bool
    {
        foreach (self::EXCLUDED as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/') || str_starts_with($path, $prefix . '?')) {
                return false;
            }
        }

        // A story's downloads and any address that ends like a feed are never drawn in a theme.
        return preg_match('#^/story/[^/]+/download/#', $path) !== 1 && preg_match('#\.(?:xml|rss|atom|json)\z#i', $path) !== 1;
    }

    /**
     * The address to come back to, rebuilt from the path and the query without the preview name. Only a path on this site
     * ever reaches a redirect.
     */
    private function returnPath(Request $request, string $path): string
    {
        $query = array_filter($request->query, static fn (mixed $value, mixed $key): bool => $key !== 'theme' && is_string($key) && (is_string($value) || is_array($value)), ARRAY_FILTER_USE_BOTH);
        $text = $query === [] ? '' : '?' . http_build_query($query);
        $return = BasePath::get() . ($path === '' ? '/' : $path) . $text;

        return preg_match('#^/(?![/\\\\])[^\s"\'<>\\\\]{0,500}\z#', $return) === 1 ? $return : BasePath::get() . '/';
    }

    // ---- usable names ---------------------------------------------------------------------------------------------------

    /** Installed, valid and fitting this site. The built-in theme is the last resort, never something to pick. */
    public function previewable(string $slug): bool
    {
        if (!ThemeCatalog::isValidSlug($slug)) {
            return false;
        }

        $theme = $this->catalog->find($slug);

        return $theme !== null && !$theme->builtIn && $this->usable($theme);
    }

    private function usable(ThemeInfo $theme): bool
    {
        return $theme->unmetRequirements(PHP_VERSION, $this->appVersion) === [];
    }

    // ---- the Administrator's preview ---------------------------------------------------------------------------------------

    /** The theme being previewed in this person's session, whether or not it is still usable. */
    public function storedPreview(): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $slug = $_SESSION[self::PREVIEW_KEY] ?? null;

        return is_string($slug) && ThemeCatalog::isValidSlug($slug) ? $slug : null;
    }

    private function rememberPreview(string $slug): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::PREVIEW_KEY] = $slug;
        }
    }

    public function clearPreview(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::PREVIEW_KEY]);
        }

        $this->previewing = null;
    }

    /**
     * Makes the previewed theme the site's theme and ends the preview. Null when there is nothing usable to make active.
     */
    public function activatePreview(ThemeSettings $settings, User $actor, string $ipAddress): ?ThemeInfo
    {
        $slug = $this->storedPreview();

        if ($slug === null || !$this->previewable($slug) || !$settings->activate($slug, $actor, $ipAddress)) {
            return null;
        }

        $this->clearPreview();

        return $this->catalog->find($slug);
    }

    // ---- what the themes print -----------------------------------------------------------------------------------------------

    /**
     * Data for the theme switcher's part, or null when it should print nothing.
     *
     * @return array<string, mixed>|null
     */
    public function switchData(): ?array
    {
        if (!$this->applies || $this->previewing !== null || !$this->enabled()) {
            return null;
        }

        $offered = $this->offered();

        if (count($offered) < 2) {
            return null;
        }

        $site = $this->renderer->activeTheme()->slug;
        $current = $this->visitorSlug ?? $site;
        $options = [];

        foreach ($offered as $slug => $theme) {
            $options[] = ['value' => $slug, 'label' => $theme->name, 'selected' => $slug === $current];
        }

        return [
            'action' => BasePath::get() . '/theme',
            'token' => $this->token($this->ip),
            'label' => 'Theme',
            'submit' => 'Use this theme',
            'return' => $this->returnTo,
            'options' => $options,
        ];
    }

    /**
     * Data for the preview banner's part, or null when no preview is going on.
     *
     * @return array<string, mixed>|null
     */
    public function bannerData(): ?array
    {
        if ($this->previewing === null) {
            return null;
        }

        $theme = $this->catalog->find($this->previewing);

        if ($theme === null) {
            return null;
        }

        return [
            'name' => $theme->name,
            'text' => 'You are previewing the ' . $theme->name . ' theme. Only you can see this. Nobody else is shown it.',
            'token' => Csrf::token(self::PREVIEW_TOKEN),
            'activate_action' => AdminUrl::to('appearance/preview/activate'),
            'activate_label' => 'Make this the active theme',
            'exit_action' => AdminUrl::to('appearance/preview/exit'),
            'exit_label' => 'Exit preview',
            'return' => $this->returnTo,
        ];
    }

    // ---- the visitor's own choice ------------------------------------------------------------------------------------------

    /**
     * A token that proves the form came from one of this site's own pages. It is signed with the site's key and the
     * visitor's address and lasts a day or two, so it needs no session: a visitor with no account gets no cookie just for
     * looking at a page.
     */
    public function token(string $ip, ?int $now = null): string
    {
        return $this->sign($ip, intdiv($now ?? time(), 86400));
    }

    public function validToken(?string $token, string $ip, ?int $now = null): bool
    {
        if ($token === null || $token === '' || $this->secretKey === '') {
            return false;
        }

        $day = intdiv($now ?? time(), 86400);

        return hash_equals($this->sign($ip, $day), $token) || hash_equals($this->sign($ip, $day - 1), $token);
    }

    private function sign(string $ip, int $day): string
    {
        return hash_hmac('sha256', 'theme-choice|' . $ip . '|' . $day, $this->secretKey);
    }

    /**
     * Records a visitor's pick: on the account of a signed-in reader, in a cookie otherwise. Choosing the site's own theme
     * clears the choice. Returns false when nothing was changed, for a name that is not on the offered list.
     *
     * @return array{changed: bool, cookie: string|null} the Set-Cookie value to send, or null for none
     */
    public function choose(string $slug, ?User $user, bool $secure): array
    {
        $offered = $this->offered();
        $site = $this->renderer->activeTheme()->slug;

        // The offered list only holds installed, valid folder names, so a name that is not on it never goes any further.
        if (!$this->enabled() || !isset($offered[$slug])) {
            return ['changed' => false, 'cookie' => null];
        }

        $keep = $slug === $site ? null : $slug;

        if ($user !== null) {
            $this->preferences->saveTheme($user->id, $keep);

            return ['changed' => true, 'cookie' => null];
        }

        return ['changed' => true, 'cookie' => $this->cookie($keep, $secure)];
    }

    /**
     * Only a folder name from the offered list ever goes into the cookie. SameSite=Lax, and Secure when the site is on HTTPS.
     */
    private function cookie(?string $slug, bool $secure): string
    {
        $path = BasePath::get() === '' ? '/' : BasePath::get();
        $parts = [self::COOKIE . '=' . ($slug ?? ''), 'Path=' . $path, 'SameSite=Lax', 'HttpOnly'];

        if ($slug === null) {
            $parts[] = 'Max-Age=0';
            $parts[] = 'Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        } else {
            $parts[] = 'Max-Age=' . self::COOKIE_SECONDS;
        }

        if ($secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }
}
