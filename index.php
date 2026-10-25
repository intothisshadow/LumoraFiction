<?php

/**
 * Front controller: every request that does not match a real file is routed here.
 *
 * @package LumoraFiction
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Lumora Fiction requires PHP 8.2 or newer.';
    exit(1);
}

define('LF_ROOT', __DIR__);

require_once LF_ROOT . '/app/Core/Autoloader.php';
require_once LF_ROOT . '/app/Helpers/hooks.php';

$autoloader = new LumoraFiction\Core\Autoloader();
$autoloader->addNamespace('LumoraFiction', LF_ROOT . '/app');
$autoloader->register();

$errors = new LumoraFiction\Core\Errors\ErrorHandler(LF_ROOT . '/storage/logs');
$errors->register();

if (!LumoraFiction\Core\Kernel::isInstalled(LF_ROOT)) {
    $basePath = LumoraFiction\Core\Http\BasePath::detect($_SERVER);
    $requestPath = (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?#');

    // The installer's browser-side check asks for this address. Getting this answer proves clean
    // web addresses reach the application, because no file exists at this path.
    if ($requestPath === $basePath . '/rewrite-check') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo 'lumora-fiction-rewrite-ok';
        exit(0);
    }

    // The target is built from the script's own location, never from the Host header.
    if (is_file(LF_ROOT . '/install/index.php')) {
        header('Location: ' . $basePath . '/install/', true, 302);
        exit(0);
    }

    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: 3600');
    echo 'Lumora Fiction is not installed yet.';
    exit(0);
}

// While an update or restore runs, visitors get a plain page before the application starts: the tables may be
// mid-replacement, so nothing here may ask the database anything. The admin area is never held.
LumoraFiction\Core\Http\BasePath::set(LumoraFiction\Core\Http\BasePath::detect($_SERVER));
$held = LumoraFiction\Core\Http\MaintenanceFlag::blocks(LF_ROOT, LumoraFiction\Core\Http\BasePath::stripFrom((string) ($_SERVER['REQUEST_URI'] ?? '/')));

if ($held !== null) {
    $held->send();
    exit(0);
}

try {
    $kernel = LumoraFiction\Core\Kernel::boot(LF_ROOT, $errors, $_SERVER);
} catch (LumoraFiction\Core\Database\DatabaseConnectionException $exception) {
    // A database outage is temporary and not a bug, so it gets a 503 rather than a generic 500.
    $errors->report($exception);

    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: 60');
    echo 'The site is temporarily unavailable. Please try again shortly.';
    exit(0);
}

$kernel->handle((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), (string) ($_SERVER['REQUEST_URI'] ?? '/'));
