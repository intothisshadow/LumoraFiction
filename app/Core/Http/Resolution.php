<?php

/**
 * The outcome of matching a request against the registered routes.
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

use Closure;

/**
 * Routing returns this value instead of sending headers itself, which keeps the router free of
 * side effects and lets the caller decide how to present a 404 or 405.
 */
final class Resolution
{
    /**
     * @param array<string, string> $params
     * @param list<string> $allowedMethods
     */
    private function __construct(
        public readonly int $status,
        public readonly ?Closure $handler = null,
        public readonly array $params = [],
        public readonly array $allowedMethods = [],
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public static function found(callable $handler, array $params): self
    {
        return new self(200, $handler(...), $params);
    }

    public static function notFound(): self
    {
        return new self(404);
    }

    /**
     * @param list<string> $allowedMethods
     */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(405, allowedMethods: $allowedMethods);
    }

    public function isFound(): bool
    {
        return $this->status === 200;
    }
}
