<?php

/**
 * How urgent a site alert is.
 *
 * @package LumoraFiction
 * @subpackage Models
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Models;

enum AlertSeverity: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Info = 'info';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::Warning => 'Warning',
            self::Info => 'Notice',
        };
    }

    /** Lower is more serious, so a list sorted by rank starts with what matters most. */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::Warning => 1,
            self::Info => 2,
        };
    }

    /** The admin's existing notice style that matches this severity. */
    public function variant(): string
    {
        return match ($this) {
            self::Critical => 'error',
            self::Warning => 'warning',
            self::Info => 'info',
        };
    }

    /** Whether the header count includes it. */
    public function needsAttention(): bool
    {
        return $this !== self::Info;
    }
}
