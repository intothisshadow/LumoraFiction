<?php

/**
 * Thrown when a redirect is not acceptable; the message is written for the administrator.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use RuntimeException;

final class RedirectException extends RuntimeException
{
    /**
     * @param string $field the form field the message belongs to, or "form" for the whole request
     */
    public function __construct(string $message, public readonly string $field = 'form')
    {
        parent::__construct($message);
    }
}
