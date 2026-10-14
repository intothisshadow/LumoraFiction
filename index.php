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

// Until the installer exists, a missing config is the only state this entry point can reach.
if (!LumoraFiction\Core\Kernel::isInstalled(LF_ROOT)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: 3600');
    echo 'Lumora Fiction is not installed yet.';
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
