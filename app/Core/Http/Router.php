<?php

/**
 * Dependency-free router that matches a request method and path to a handler.
 *
 * @package LumoraFiction
 * @subpackage Http
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Http;

use InvalidArgumentException;

/**
 * Patterns use {param} for a single path segment and {param*} for a greedy match across slashes.
 * Routes are tried in registration order, so register greedy patterns last or they will shadow
 * every fixed route after them. The router only matches; it never sends output or headers.
 */
final class Router
{
    private const PLACEHOLDER = '#\{([a-zA-Z_][a-zA-Z0-9_]*)(\*)?\}#';

    /**
     * @var list<array{method: string, pattern: string, regex: string, handler: callable, name: string|null}>
     */
    private array $routes = [];

    public function get(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->add('GET', $pattern, $handler, $name);
    }

    public function post(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->add('POST', $pattern, $handler, $name);
    }

    public function put(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->add('PUT', $pattern, $handler, $name);
    }

    public function patch(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->add('PATCH', $pattern, $handler, $name);
    }

    public function delete(string $pattern, callable $handler, ?string $name = null): void
    {
        $this->add('DELETE', $pattern, $handler, $name);
    }

    public function add(string $method, string $pattern, callable $handler, ?string $name = null): void
    {
        if ($pattern === '' || $pattern[0] !== '/') {
            throw new InvalidArgumentException('Route patterns must start with a slash.');
        }

        if ($name !== null) {
            foreach ($this->routes as $route) {
                if ($route['name'] === $name) {
                    throw new InvalidArgumentException('A route named "' . $name . '" is already registered.');
                }
            }
        }

        $pattern = $pattern === '/' ? '/' : rtrim($pattern, '/');

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => $this->compile($pattern),
            'handler' => $handler,
            'name' => $name,
        ];
    }

    public function resolve(string $method, string $uri): Resolution
    {
        $path = $this->normalizePath($uri);

        if ($path === null) {
            return Resolution::notFound();
        }

        // A HEAD request is answered with the GET handler; the web server drops the body.
        $method = strtoupper($method);
        $method = $method === 'HEAD' ? 'GET' : $method;
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];

                continue;
            }

            $params = [];

            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            return Resolution::found($route['handler'], $params);
        }

        if ($allowed !== []) {
            return Resolution::methodNotAllowed(array_values(array_unique($allowed)));
        }

        return Resolution::notFound();
    }

    /**
     * Builds the path for a named route, without any install base path.
     *
     * @param array<string, string|int> $params
     */
    public function urlFor(string $name, array $params = []): string
    {
        foreach ($this->routes as $route) {
            if ($route['name'] !== $name) {
                continue;
            }

            $url = preg_replace_callback(
                self::PLACEHOLDER,
                static function (array $match) use ($params, $name): string {
                    if (!array_key_exists($match[1], $params)) {
                        throw new InvalidArgumentException('Route "' . $name . '" needs a "' . $match[1] . '" parameter.');
                    }

                    $value = (string) $params[$match[1]];

                    if (($match[2] ?? '') === '*') {
                        return implode('/', array_map('rawurlencode', explode('/', $value)));
                    }

                    // A slash would be decoded back into a separator when the URL is requested,
                    // so the generated URL would no longer match the route it came from.
                    if ($value === '' || str_contains($value, '/')) {
                        throw new InvalidArgumentException('Route "' . $name . '" parameter "' . $match[1] . '" must be a single non-empty path segment.');
                    }

                    return rawurlencode($value);
                },
                $route['pattern'],
            );

            return (string) $url;
        }

        throw new InvalidArgumentException('No route named "' . $name . '".');
    }

    /**
     * Returns the decoded, canonical path, or null when the request path cannot be a valid route.
     */
    private function normalizePath(string $uri): ?string
    {
        // Cut the query string and fragment by hand: parse_url() reads "//name" as a host.
        $path = $uri;

        foreach (['?', '#'] as $delimiter) {
            $position = strpos($path, $delimiter);

            if ($position !== false) {
                $path = substr($path, 0, $position);
            }
        }

        $decoded = rawurldecode($path);

        // NUL and other control characters, or bytes that are not valid UTF-8, never belong in a
        // route and are a common way to smuggle values past later checks.
        if (preg_match('/[\x00-\x1f\x7f]/', $decoded) === 1 || preg_match('//u', $decoded) !== 1) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '') {
                continue;
            }

            // Dot segments are rejected outright so no handler ever receives a parameter that
            // could climb out of a directory it builds a path from.
            if ($segment === '.' || $segment === '..') {
                return null;
            }

            $segments[] = $segment;
        }

        // A request for the front controller itself carries no route information of its own.
        if (($segments[0] ?? '') === 'index.php') {
            array_shift($segments);
        }

        return '/' . implode('/', $segments);
    }

    private function compile(string $pattern): string
    {
        $regex = '';
        $offset = 0;

        preg_match_all(self::PLACEHOLDER, $pattern, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $seen = [];

        foreach ($found as $match) {
            $name = $match[1][0];

            if (isset($seen[$name])) {
                throw new InvalidArgumentException('Route pattern repeats the parameter "' . $name . '".');
            }

            $seen[$name] = true;

            $regex .= preg_quote(substr($pattern, $offset, $match[0][1] - $offset), '#');
            $regex .= '(?P<' . $name . '>' . (($match[2][0] ?? '') === '*' ? '.+' : '[^/]+') . ')';
            $offset = $match[0][1] + strlen($match[0][0]);
        }

        $regex .= preg_quote(substr($pattern, $offset), '#');

        return '#^' . $regex . '$#u';
    }
}
