<?php

/**
 * Thrown when the details of a static page are not acceptable; carries a message for each field.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use RuntimeException;

final class PageValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $errors field name => problem
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The page details are not valid.');
    }
}
