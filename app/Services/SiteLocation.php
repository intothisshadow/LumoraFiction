<?php

/**
 * Remembers where the site was installed or last confirmed, notices when it has moved, and applies a confirmed correction.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Http\BasePath;
use LumoraFiction\Core\Http\Request;
use RuntimeException;

/**
 * The record lives in the settings table, not in config.php or under storage/: it travels with the data, is readable
 * without the file configuration, and an update never rewrites it. `site_url` itself is never derived from a request,
 * because a forged Host header must not be able to steer an emailed password-reset link; a request is only ever
 * compared with it, and only on admin screens, so the proposal is something an administrator reviews and confirms.
 */
final class SiteLocation
{
    public const SETTING_RECORD = 'site.location';

    public const SETTING_KEPT = 'site.location_kept';

    /** Addresses an administrator chose to keep seeing the site under, so a staging alias or CDN is not nagged about. */
    private const KEPT_MAX = 20;

    public function __construct(
        private readonly Configuration $config,
        private readonly string $root,
        private readonly AuditLogService $audit,
        private readonly string $configFile,
    ) {
    }

    /**
     * What the installer stores, so a fresh install has a record from its first request.
     *
     * @return array{url: string, path: string}
     */
    public static function initialRecord(string $url, string $root): array
    {
        return ['url' => self::normalise($url) ?? '', 'path' => self::realRoot($root)];
    }

    /**
     * Creates the record from the current configuration when an install has none yet (a site installed before this
     * existed), without raising anything, and keeps it in step when someone edited config.php by hand.
     */
    public function seed(?Request $request = null): void
    {
        $record = $this->record();
        $configured = self::normalise((string) $this->config->get('site_url', '')) ?? '';

        if ($record === null) {
            $this->config->setSetting(self::SETTING_RECORD, ['url' => $configured, 'path' => self::realRoot($this->root)]);

            return;
        }

        // A hand edit of config.php that matches the address in use is as good as a confirmation.
        if ($request !== null && $configured !== '' && $record['url'] !== $configured && $this->currentAddress($request) === $configured && $record['path'] === self::realRoot($this->root)) {
            $this->config->setSetting(self::SETTING_RECORD, ['url' => $configured, 'path' => $record['path']]);
        }
    }

    /**
     * @return array{url: string, path: string}|null
     */
    public function record(): ?array
    {
        $value = $this->config->setting(self::SETTING_RECORD);

        if (!is_array($value) || !is_string($value['url'] ?? null) || !is_string($value['path'] ?? null)) {
            return null;
        }

        return ['url' => $value['url'], 'path' => $value['path']];
    }

    /**
     * What differs, or null when the site is where it says it is. Only an admin request is looked at, so a public page
     * pays nothing and a visitor's Host header is never read.
     *
     * @return array{configured: string, current: string, recordedPath: string, currentPath: string, addressChanged: bool, pathChanged: bool}|null
     */
    public function mismatch(Request $request): ?array
    {
        if (!$this->isAdminRequest($request)) {
            return null;
        }

        $this->seed($request);
        $record = $this->record();

        if ($record === null) {
            return null;
        }

        $configured = self::normalise((string) $this->config->get('site_url', '')) ?? '';
        $current = $this->currentAddress($request);
        $currentPath = self::realRoot($this->root);

        $addressChanged = $configured !== '' && $current !== null && $current !== $configured && !in_array($current, $this->kept(), true);
        $pathChanged = $record['path'] !== '' && $record['path'] !== $currentPath;

        if (!$addressChanged && !$pathChanged) {
            return null;
        }

        return [
            'configured' => $configured,
            'current' => $current ?? '',
            'recordedPath' => $record['path'],
            'currentPath' => $currentPath,
            'addressChanged' => $addressChanged,
            'pathChanged' => $pathChanged,
        ];
    }

    /**
     * The address this admin request arrived on: scheme (the trusted-proxy aware answer), host, port and the
     * subdirectory, normalised the same way as `site_url`. Null when the Host header is not a plain host name.
     */
    public function currentAddress(Request $request): ?string
    {
        $host = (string) ($request->server['HTTP_HOST'] ?? '');

        if ($host === '' || preg_match('/^[A-Za-z0-9.\-]+(:[0-9]{1,5})?\z|^\[[0-9A-Fa-f:]+\](:[0-9]{1,5})?\z/', $host) !== 1) {
            return null;
        }

        return self::normalise(($request->isHttps() ? 'https' : 'http') . '://' . $host . BasePath::get());
    }

    /**
     * Checks an address an administrator typed. Well-formed http or https, a host, no credentials, query or fragment.
     *
     * @throws RuntimeException with a sentence fit to show
     */
    public function validAddress(string $address): string
    {
        $address = trim($address);
        $normalised = preg_match('/[\x00-\x20\x7f]/', $address) === 1 ? null : self::normalise($address);

        if ($normalised === null) {
            throw new RuntimeException('Enter the full web address of the site, starting with http:// or https://, with no query or anchor.');
        }

        return $normalised;
    }

    /**
     * Writes the confirmed address into config.php and brings the record up to date. Returns false, changing nothing,
     * when the file cannot be written; the screen then shows the line to change by hand.
     */
    public function confirm(int $actorId, string $address, string $ipAddress = ''): bool
    {
        $address = $this->validAddress($address);
        $old = self::normalise((string) $this->config->get('site_url', '')) ?? '';
        $oldRecord = $this->record();

        if ($address !== $old) {
            if (!$this->configWritable()) {
                return false;
            }

            $this->config->updateFileValue('site_url', $address);
        }

        $path = self::realRoot($this->root);
        $this->config->setSetting(self::SETTING_RECORD, ['url' => $address, 'path' => $path]);
        $this->config->setSetting(self::SETTING_KEPT, []);
        $this->audit->record($actorId, 'site.location_confirmed', 'setting', self::SETTING_RECORD, [
            'old_url' => $old,
            'new_url' => $address,
            'old_path' => $oldRecord['path'] ?? '',
            'new_path' => $path,
        ], $ipAddress);

        return true;
    }

    public function configWritable(): bool
    {
        return is_file($this->configFile) && is_writable($this->configFile) && is_writable(dirname($this->configFile));
    }

    /** The line to put in config.php when the screen cannot write it. */
    public static function manualLine(string $address): string
    {
        return "'site_url' => " . var_export($address, true) . ',';
    }

    /** Keeps the address in use from being flagged again. */
    public function keep(int $actorId, Request $request): bool
    {
        $current = $this->currentAddress($request);

        if ($current === null) {
            return false;
        }

        $kept = array_values(array_unique([...$this->kept(), $current]));
        $this->config->setSetting(self::SETTING_KEPT, array_slice($kept, -self::KEPT_MAX));
        $this->audit->record($actorId, 'site.location_kept', 'setting', self::SETTING_KEPT, ['address' => $current], $request->ip());

        return true;
    }

    public function resetKept(int $actorId, string $ipAddress = ''): void
    {
        $this->config->setSetting(self::SETTING_KEPT, []);
        $this->audit->record($actorId, 'site.location_kept_reset', 'setting', self::SETTING_KEPT, [], $ipAddress);
    }

    /**
     * @return list<string>
     */
    public function kept(): array
    {
        $value = $this->config->setting(self::SETTING_KEPT, []);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * Lower-case scheme and host, default port dropped, no trailing slash. Null for anything that is not a plain
     * http(s) address: other schemes, credentials, a query or fragment, or no host.
     */
    public static function normalise(string $address): ?string
    {
        $parts = parse_url(trim($address));

        if ($parts === false || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower($parts['host']);

        if (preg_match('/^[a-z0-9.\-]+\z|^\[[0-9a-f:]+\]\z/', $host) !== 1) {
            return null;
        }

        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        if ($path !== '' && preg_match('#^/[A-Za-z0-9._~%/\-]*\z#', $path) !== 1) {
            return null;
        }

        return $scheme . '://' . $host . ($port !== null && $port !== $default ? ':' . $port : '') . $path;
    }

    private function isAdminRequest(Request $request): bool
    {
        // The router strips the base path from the address before dispatching; stripping is a no-op when it is already gone.
        $path = BasePath::stripFrom((string) parse_url($request->uri, PHP_URL_PATH));

        return $path === '/admin' || str_starts_with($path, '/admin/');
    }

    private static function realRoot(string $root): string
    {
        $real = realpath($root);

        return rtrim($real === false ? $root : $real, '/');
    }
}
