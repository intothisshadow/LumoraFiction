<?php

/**
 * A series or collection change that was refused, with the reason in plain words.
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

/**
 * Thrown both for a field that is not acceptable (then $errors names the fields) and for a person who may not do
 * something (then $errors is empty and the message says why). The message never names a login or an internal class.
 */
final class GroupingException extends RuntimeException
{
    /**
     * @param array<string, string> $errors field name => problem, for a form that can show them beside the fields
     */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
