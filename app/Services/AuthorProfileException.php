<?php

/**
 * Thrown when an author's profile text or picture cannot be saved; carries a message per form field.
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

final class AuthorProfileException extends RuntimeException
{
    /**
     * @param array<string, string> $errors field name => problem, for the fields "about" and "avatar"
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The author profile is not valid.');
    }
}
