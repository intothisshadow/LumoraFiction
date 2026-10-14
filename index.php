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

$autoloader = new LumoraFiction\Core\Autoloader();
$autoloader->addNamespace('LumoraFiction', LF_ROOT . '/app');
$autoloader->register();

// Until the installer exists, a missing config is the only state this entry point can reach.
if (!is_file(LF_ROOT . '/config/config.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: 3600');
    echo 'Lumora Fiction is not installed yet.';
    exit(0);
}

http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
echo 'Lumora Fiction is not available yet.';
exit(0);
