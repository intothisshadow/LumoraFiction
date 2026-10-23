<?php

/**
 * The site-health checks core itself makes, offered to the alerts list.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Admin;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Models\AlertSeverity;
use LumoraFiction\Models\SiteAlert;

/**
 * Every message is fixed text: no folder path, setting value or visitor input is ever put in one.
 */
final class CoreAlerts
{
    public function __construct(
        private readonly Configuration $config,
        private readonly string $root,
    ) {
    }

    public function register(HookManager $hooks): void
    {
        $hooks->addFilter('admin_alerts', $this->add(...), 10);
    }

    /**
     * @param mixed $alerts the alerts collected so far
     * @param mixed $request the Request being answered
     * @return list<mixed>
     */
    public function add(mixed $alerts, mixed $request = null): array
    {
        $alerts = is_array($alerts) ? array_values($alerts) : [];

        if (is_dir($this->root . '/install')) {
            $alerts[] = new SiteAlert('install.folder', AlertSeverity::Critical, 'The installer folder is still on the server. Delete the "install" folder; leaving it there is a security risk.');
        }

        if ($this->config->get('debug', false) === true) {
            $alerts[] = new SiteAlert('debug.on', AlertSeverity::Warning, 'Debug mode is on, so visitors can see technical details when something goes wrong. Turn it off on a live site.');
        }

        $siteUrl = (string) $this->config->get('site_url', '');

        if ($siteUrl === '') {
            $alerts[] = new SiteAlert('site.url_missing', AlertSeverity::Critical, 'No site address is set, so links in emails cannot be created.');
        }

        if ($request instanceof Request && !$request->isHttps()) {
            $message = 'This site is not being served over HTTPS. Passwords and sessions travel unprotected; ask your host for a certificate.';
            $path = '';

            // A site address that already says https means the certificate exists and something in front of the site
            // is probably hiding it, which the trusted-proxy setting is for.
            if (str_starts_with(strtolower($siteUrl), 'https://')) {
                $message .= ' The site address starts with https://, so a proxy or load balancer may be hiding HTTPS from the site: check the trusted proxies under Settings > Security.';
                $path = 'settings/security';
            }

            $alerts[] = new SiteAlert('https.off', AlertSeverity::Warning, $message, '', $path);
        }

        if (!is_dir($this->root . '/storage') || !is_writable($this->root . '/storage')) {
            $alerts[] = new SiteAlert('storage.unwritable', AlertSeverity::Warning, 'The storage folder cannot be written to, so logs, sessions and cached files will fail. Ask your host to let the web server write to it.', '', 'maintenance/system-information');
        }

        if (is_dir($this->root . '/content/uploads') && !is_writable($this->root . '/content/uploads')) {
            $alerts[] = new SiteAlert('uploads.unwritable', AlertSeverity::Warning, 'The uploads folder cannot be written to, so cover and author pictures cannot be saved. Ask your host to let the web server write to it.', '', 'maintenance/system-information');
        }

        return $alerts;
    }
}
