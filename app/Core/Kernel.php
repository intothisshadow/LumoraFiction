<?php

/**
 * The composition root: wires the core services together and dispatches a request.
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

namespace LumoraFiction\Core;

use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Errors\ErrorHandler;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Hooks\Hooks;
use LumoraFiction\Core\Http\BasePath;
use LumoraFiction\Core\Http\Router;

/**
 * One Kernel is built per request and handed to the entry script, instead of services being
 * reached through global state. Later features add their services here.
 */
final class Kernel
{
    public function __construct(
        public readonly Configuration $config,
        public readonly Database $database,
        public readonly ErrorHandler $errors,
        public readonly HookManager $hooks,
        public readonly Router $router,
    ) {
    }

    public static function isInstalled(string $root): bool
    {
        return is_file($root . '/config/config.php');
    }

    /**
     * Builds the kernel for an installed site.
     *
     * @param array<string, mixed> $server
     * @throws Database\DatabaseConnectionException when the database cannot be reached
     */
    public static function boot(string $root, ErrorHandler $errors, array $server = []): self
    {
        $config = new Configuration($root . '/config/config.php');

        $errors->setDebug($config->get('debug', false) === true);

        $database = Database::connect(
            host: (string) $config->get('db_host'),
            database: (string) $config->get('db_name'),
            username: (string) $config->get('db_user'),
            password: (string) $config->get('db_password'),
            tablePrefix: (string) $config->get('table_prefix'),
            charset: (string) $config->get('db_charset', 'utf8mb4'),
            port: (int) $config->get('db_port', 3306),
        );

        $config->bindDatabase($database);

        // The shared instance is reused, not replaced, so anything registered before boot
        // (by bootstrap code or a test) is not silently dropped.
        $hooks = Hooks::instance();

        BasePath::set(BasePath::detect($server));

        $router = new Router();

        $kernel = new self($config, $database, $errors, $hooks, $router);

        // Plugins and later features add their routes here.
        $hooks->doAction('routes_register', $router);
        $hooks->doAction('kernel_booted', $kernel);

        return $kernel;
    }

    /**
     * Matches the request to a route and runs its handler, or sends a 404 or 405 page.
     */
    public function handle(string $method, string $uri): void
    {
        $resolution = $this->router->resolve($method, BasePath::stripFrom($uri));

        if ($resolution->isFound() && $resolution->handler !== null) {
            ($resolution->handler)($resolution->params);

            return;
        }

        if (!headers_sent()) {
            http_response_code($resolution->status);

            if ($resolution->status === 405) {
                header('Allow: ' . implode(', ', $resolution->allowedMethods));
            }

            header('Content-Type: text/html; charset=utf-8');
        }

        $this->renderStatusPage($resolution->status);
    }

    private function renderStatusPage(int $status): void
    {
        $title = $status === 405 ? 'Method not allowed' : 'Page not found';
        $message = $status === 405
            ? 'That action is not available for this address.'
            : 'The page you are looking for does not exist.';

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $title . '</title></head><body><h1>' . $title . '</h1><p>' . $message . '</p></body></html>';
    }
}
