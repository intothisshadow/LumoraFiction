<?php

/**
 * The marker file that takes the public site offline while an update or restore is running.
 *
 * @package LumoraFiction
 * @subpackage Http
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Http;

/**
 * A file, not a setting, and read before the application boots: while tables are being replaced the database cannot be
 * asked anything, and a visitor must still get a calm 503 rather than an error. The admin area is exempt so the
 * administrator can watch the update and finish or undo it.
 */
final class MaintenanceFlag
{
    public const RETRY_AFTER_SECONDS = 120;

    public static function path(string $root): string
    {
        return $root . '/storage/updates/maintenance.flag';
    }

    /**
     * @return array{since: string, reason: string, message: string}|null
     */
    public static function read(string $root): ?array
    {
        $path = self::path($root);

        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return [
            'since' => is_array($decoded) && is_string($decoded['since'] ?? null) ? $decoded['since'] : '',
            'reason' => is_array($decoded) && is_string($decoded['reason'] ?? null) ? $decoded['reason'] : 'update',
            'message' => is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : '',
        ];
    }

    public static function raise(string $root, string $reason, string $message): bool
    {
        $directory = dirname(self::path($root));

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        return file_put_contents(self::path($root), json_encode(['since' => gmdate('Y-m-d H:i:s'), 'reason' => $reason, 'message' => $message], JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
    }

    public static function clear(string $root): void
    {
        if (is_file(self::path($root))) {
            unlink(self::path($root));
        }
    }

    /**
     * The page for a visitor while the flag is up, or null when this request may go on (no flag, or an admin address).
     *
     * @param string $path the request path with the base path already removed
     */
    public static function blocks(string $root, string $path): ?Response
    {
        $flag = self::read($root);

        if ($flag === null) {
            return null;
        }

        $path = (string) strtok($path, '?#');

        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            return null;
        }

        return self::page($flag['message']);
    }

    /**
     * A page that needs nothing from the application: no database, no theme, no stylesheet.
     */
    public static function page(string $message = ''): Response
    {
        $text = trim($message) !== '' ? $message : 'The site is being updated and will be back in a few minutes.';
        $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return Response::html(
            '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>Down for maintenance</title></head><body>'
            . '<main><h1>Down for maintenance</h1><p>' . $safe . '</p></main></body></html>',
            503,
            ['Retry-After' => (string) self::RETRY_AFTER_SECONDS, 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex'],
        );
    }
}
