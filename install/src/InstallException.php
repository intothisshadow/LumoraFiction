<?php

/**
 * Thrown when installation cannot finish; every message it carries is safe to show the person installing.
 *
 * @package LumoraFiction
 * @subpackage Installer
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Installer;

use RuntimeException;
use Throwable;

/**
 * Anything that could contain a hostname, a path, or SQL stays in the previous exception, which is
 * written to the error log and never shown.
 */
final class InstallException extends RuntimeException
{
    /**
     * @param list<string> $messages
     */
    public function __construct(public readonly array $messages, ?Throwable $previous = null)
    {
        parent::__construct(implode(' ', $messages), 0, $previous);
    }

    public static function because(string $message, ?Throwable $previous = null): self
    {
        return new self([$message], $previous);
    }
}
