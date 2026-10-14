<?php

/**
 * Thrown when creating an account collides with an existing login name or email address.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use RuntimeException;

final class DuplicateAccountException extends RuntimeException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct('An account with that ' . $field . ' already exists.');
    }
}
