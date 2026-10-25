<?php

/**
 * Finds the newest Lumora Fiction release on GitHub and downloads its package, checked against the published checksum.
 *
 * @package LumoraFiction
 * @subpackage Update
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Update;

/**
 * Nothing here is ever installed by itself: it only reports what is available and fetches a file to be reviewed. The
 * checksum comes from a second file attached to the same release, so a package damaged or swapped in transit is caught,
 * and a release that publishes no checksum is refused rather than trusted blindly.
 */
final class ReleaseFeed
{
    public const DEFAULT_REPOSITORY = 'intothisshadow/LumoraFiction';

    private const API_LIMIT_BYTES = 2 * 1024 * 1024;

    private const CHECKSUM_LIMIT_BYTES = 4096;

    private const NOTES_LIMIT = 4000;

    public function __construct(private readonly HttpTransport $http)
    {
    }

    public static function isValidRepository(string $repository): bool
    {
        return preg_match('#^[A-Za-z0-9](?:[A-Za-z0-9_.-]{0,60})/[A-Za-z0-9_.-]{1,100}\z#', $repository) === 1;
    }

    /**
     * Only the project's own release hosts: GitHub and the addresses it serves release files from.
     */
    public static function isTrustedUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($parts['scheme'] ?? '') === 'https'
            && ($host === 'github.com' || $host === 'api.github.com' || str_ends_with($host, '.githubusercontent.com'));
    }

    /**
     * @return array{version: string, name: string, notes: string, url: string, published: string, prerelease: bool, package_url: string, package_name: string, checksum_url: string}|null
     *         null when the repository has no suitable release
     * @throws UpdateException when GitHub cannot be reached or answers with something unusable
     */
    public function latest(string $repository, bool $includePrereleases): ?array
    {
        if (!self::isValidRepository($repository)) {
            throw new UpdateException('The repository name is not valid. Use the form owner/name.');
        }

        $raw = $this->http->fetch('https://api.github.com/repos/' . $repository . '/releases?per_page=15', self::API_LIMIT_BYTES);
        $releases = json_decode($raw, true);

        if (!is_array($releases)) {
            throw new UpdateException('GitHub answered with something this page cannot read.');
        }

        $best = null;

        foreach ($releases as $release) {
            $candidate = is_array($release) ? $this->describe($release) : null;

            if ($candidate === null || (!$includePrereleases && $candidate['prerelease'])) {
                continue;
            }

            if ($best === null || version_compare($candidate['version'], $best['version'], '>')) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Downloads the release package and refuses it unless it matches the published checksum.
     *
     * @param array{package_url: string, checksum_url: string, version: string} $release
     * @throws UpdateException
     */
    public function download(array $release, string $destination): string
    {
        if ($release['checksum_url'] === '') {
            throw new UpdateException('The release publishes no SHA-256 checksum file, so its package cannot be verified and was not downloaded.');
        }

        foreach ([$release['package_url'], $release['checksum_url']] as $url) {
            if (!self::isTrustedUrl($url)) {
                throw new UpdateException('The release points to a download address that is not on GitHub, so it was not used.');
            }
        }

        $expected = self::checksumIn($this->http->fetch($release['checksum_url'], self::CHECKSUM_LIMIT_BYTES));

        if ($expected === null) {
            throw new UpdateException('The release has no readable SHA-256 checksum, so its package cannot be verified and was not downloaded.');
        }

        $this->http->download($release['package_url'], $destination, PackageInspector::MAX_PACKAGE_BYTES);

        if (!hash_equals($expected, (string) hash_file('sha256', $destination))) {
            unlink($destination);

            throw new UpdateException('The downloaded package does not match its published SHA-256 checksum, so it was thrown away. Try again; if it keeps happening, do not install it.');
        }

        return $expected;
    }

    /**
     * The 64 hex digits at the start of a checksum file ("hash  filename"), or null when there are none.
     */
    public static function checksumIn(string $text): ?string
    {
        return preg_match('/^\s*([0-9a-fA-F]{64})(?:\s|\z)/', $text, $match) === 1 ? strtolower($match[1]) : null;
    }

    /**
     * @param array<mixed> $release
     * @return array{version: string, name: string, notes: string, url: string, published: string, prerelease: bool, package_url: string, package_name: string, checksum_url: string}|null
     */
    private function describe(array $release): ?array
    {
        if (($release['draft'] ?? false) === true || !is_string($release['tag_name'] ?? null)) {
            return null;
        }

        if (preg_match('/^v?(\d+\.\d+\.\d+)\z/', $release['tag_name'], $match) !== 1) {
            return null;
        }

        $version = $match[1];
        $packageName = 'LumoraFiction-v' . $version . '.zip';
        $package = '';
        $checksum = '';

        foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $asset) {
            if (!is_array($asset) || !is_string($asset['browser_download_url'] ?? null)) {
                continue;
            }

            if (($asset['name'] ?? '') === $packageName) {
                $package = $asset['browser_download_url'];
            } elseif (($asset['name'] ?? '') === $packageName . '.sha256') {
                $checksum = $asset['browser_download_url'];
            }
        }

        if ($package === '') {
            return null;
        }

        $notes = is_string($release['body'] ?? null) ? $release['body'] : '';

        return [
            'version' => $version,
            'name' => is_string($release['name'] ?? null) && $release['name'] !== '' ? $release['name'] : 'Lumora Fiction ' . $version,
            'notes' => mb_substr($notes, 0, self::NOTES_LIMIT),
            'url' => is_string($release['html_url'] ?? null) && self::isTrustedUrl($release['html_url']) ? $release['html_url'] : '',
            'published' => is_string($release['published_at'] ?? null) ? substr($release['published_at'], 0, 10) : '',
            'prerelease' => ($release['prerelease'] ?? false) === true,
            'package_url' => $package,
            'package_name' => $packageName,
            'checksum_url' => $checksum,
        ];
    }
}
