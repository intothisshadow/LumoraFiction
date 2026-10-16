<?php

/**
 * Thrown when story or chapter input is not acceptable; carries a message per field for the form.
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

final class StoryValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $errors field name => problem
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The story details are not valid.');
    }
}
