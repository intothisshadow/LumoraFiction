<?php

/**
 * The two numbers the Sweep plugin keeps: how old a never-confirmed account must be, and how many cleanup candidates trigger a reminder.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\Sweep;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AuditLogService;

/**
 * A damaged stored value falls back to the default, so a bad setting can neither make every account look old nor turn
 * the reminder into noise.
 */
final class SweepSettings
{
    public const UNCONFIRMED_DAYS = 14;

    public const MAX_UNCONFIRMED_DAYS = 365;

    public const REMINDER_THRESHOLD = 100;

    public const MAX_REMINDER_THRESHOLD = 100000;

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    /** Days an account may wait for its email confirmation before a sweep may remove it. At least one, so a new sign-up is never a candidate. */
    public function unconfirmedDays(): int
    {
        $value = $this->config->setting('sweep.unconfirmed_days', self::UNCONFIRMED_DAYS);

        return is_int($value) && $value >= 1 && $value <= self::MAX_UNCONFIRMED_DAYS ? $value : self::UNCONFIRMED_DAYS;
    }

    /** How many candidates make the inbox reminder appear; 0 turns the reminder off. */
    public function reminderThreshold(): int
    {
        $value = $this->config->setting('sweep.reminder_threshold', self::REMINDER_THRESHOLD);

        return is_int($value) && $value >= 0 && $value <= self::MAX_REMINDER_THRESHOLD ? $value : self::REMINDER_THRESHOLD;
    }

    /**
     * @return array{unconfirmed_days: int, reminder_threshold: int}
     */
    public function current(): array
    {
        return ['unconfirmed_days' => $this->unconfirmedDays(), 'reminder_threshold' => $this->reminderThreshold()];
    }

    /**
     * @param array{unconfirmed_days: ?string, reminder_threshold: ?string} $input
     * @return array{errors: array<string, string>, values: array{unconfirmed_days: int, reminder_threshold: int}}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $current = $this->current();

        $days = $this->whole($input['unconfirmed_days'] ?? null);
        $threshold = $this->whole($input['reminder_threshold'] ?? null);

        if ($days === null || $days < 1 || $days > self::MAX_UNCONFIRMED_DAYS) {
            $errors['unconfirmed_days'] = 'Enter a number of days from 1 to ' . self::MAX_UNCONFIRMED_DAYS . '.';
            $days = $current['unconfirmed_days'];
        }

        if ($threshold === null || $threshold > self::MAX_REMINDER_THRESHOLD) {
            $errors['reminder_threshold'] = 'Enter a number from 0 to ' . self::MAX_REMINDER_THRESHOLD . '. Use 0 for no reminder.';
            $threshold = $current['reminder_threshold'];
        }

        return ['errors' => $errors, 'values' => ['unconfirmed_days' => $days, 'reminder_threshold' => $threshold]];
    }

    /**
     * @param array{unconfirmed_days: int, reminder_threshold: int} $values
     * @return list<string> the names of the settings that changed
     */
    public function save(array $values, User $actor, string $ipAddress): array
    {
        if (!$actor->can(Capability::ManageSite)) {
            return [];
        }

        $current = $this->current();
        $changes = [];

        foreach (['unconfirmed_days' => 'sweep.unconfirmed_days', 'reminder_threshold' => 'sweep.reminder_threshold'] as $field => $setting) {
            if ($values[$field] !== $current[$field]) {
                $changes[$setting] = ['from' => $current[$field], 'to' => $values[$field]];
            }
        }

        if ($changes === []) {
            return [];
        }

        $this->database->transaction(function () use ($changes, $actor, $ipAddress): void {
            foreach ($changes as $setting => $change) {
                $this->config->setSetting($setting, $change['to']);
            }

            $this->audit->record($actor->id, 'sweep.settings', 'settings', 'sweep', $changes, $ipAddress);
        });

        return array_keys($changes);
    }

    private function whole(?string $value): ?int
    {
        $value = trim((string) $value);

        return preg_match('/^\d{1,6}\z/', $value) === 1 ? (int) $value : null;
    }
}
