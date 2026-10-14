<?php

/**
 * Builds and sends the Content-Security-Policy header.
 *
 * @package LumoraFiction
 * @subpackage Security
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Security;

/**
 * Ships a strict same-origin default: core needs no inline scripts or styles and no third-party
 * origins. A theme or plugin that needs to loosen a directive uses the csp_directives filter, so
 * this class stays a plain header builder with no hook, config, or database dependency.
 */
final class ContentSecurityPolicy
{
    /**
     * @return array<string, string>
     */
    public static function defaultDirectives(): array
    {
        return [
            'default-src' => "'self'",
            'base-uri' => "'self'",
            'form-action' => "'self'",
            'frame-ancestors' => "'self'",
            'object-src' => "'none'",
            'script-src' => "'self'",
            'style-src' => "'self'",
            'img-src' => "'self'",
            'font-src' => "'self'",
            'connect-src' => "'self'",
        ];
    }

    /**
     * @param array<string, string> $directives directive name => value; the defaults are used when empty
     */
    public function __construct(private array $directives = [])
    {
        if ($this->directives === []) {
            $this->directives = self::defaultDirectives();
        }
    }

    public function header(): string
    {
        $parts = [];

        foreach ($this->directives as $name => $value) {
            // A CR or LF here would let a directive value inject a second header.
            $parts[] = str_replace(["\r", "\n", ';'], '', $name . ' ' . $value);
        }

        return implode('; ', $parts);
    }

    /**
     * A no-op when output has already started: a missing policy is far less harmful than a fatal error on every page.
     */
    public function send(bool $reportOnly = false): void
    {
        if (headers_sent()) {
            return;
        }

        header(($reportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy') . ': ' . $this->header());
    }
}
