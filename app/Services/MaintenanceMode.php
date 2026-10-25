<?php

/**
 * Maintenance mode: taking the public site offline for visitors while administrators keep working.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Security\IpRangeMatcher;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;

/**
 * Two things can put the site in maintenance: this setting (switched on by hand or by a schedule), and the marker file
 * an update raises for its own duration. They are separate so finishing an update never switches off a maintenance
 * period the administrator asked for, and an update still works when the setting cannot be read.
 */
final class MaintenanceMode
{
    public const DEFAULT_MESSAGE = 'The site is down for maintenance and will be back soon.';

    public const MAX_MESSAGE = 500;

    public const DEFAULTS = [
        'maintenance.enabled' => false,
        'maintenance.message' => '',
        'maintenance.allowlist' => '',
        'maintenance.starts' => '',
        'maintenance.ends' => '',
    ];

    private const TIME_FORMAT = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}\z/';

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * @return array{enabled: bool, message: string, allowlist: string, starts: string, ends: string}
     */
    public function current(): array
    {
        return [
            'enabled' => $this->config->setting('maintenance.enabled', false) === true,
            'message' => $this->text('maintenance.message'),
            'allowlist' => $this->text('maintenance.allowlist'),
            'starts' => $this->text('maintenance.starts'),
            'ends' => $this->text('maintenance.ends'),
        ];
    }

    /**
     * Whether visitors are being turned away now, by the switch or by the schedule.
     */
    public function isActive(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable('now', $this->zone());
        $settings = $this->current();
        $starts = $this->moment($settings['starts']);
        $ends = $this->moment($settings['ends']);

        if ($settings['enabled'] && ($ends === null || $now < $ends)) {
            return true;
        }

        return $starts !== null && $ends !== null && $now >= $starts && $now < $ends;
    }

    /**
     * Whether this visitor may see the site anyway: an allowed address, or a signed-in administrator. Editors and
     * authors have the admin area (which is never held) for their work, so the public pages are not theirs to see early.
     */
    public function admits(string $ipAddress, ?User $user): bool
    {
        if ($user !== null && $user->can(Capability::ManageSite)) {
            return true;
        }

        return IpRangeMatcher::matches($ipAddress, IpRangeMatcher::parseList($this->current()['allowlist']));
    }

    public function message(): string
    {
        $message = $this->current()['message'];

        return $message !== '' ? $message : self::DEFAULT_MESSAGE;
    }

    /**
     * @param array{enabled: bool, message: ?string, allowlist: ?string, starts: ?string, ends: ?string} $input
     * @return array{errors: array<string, string>, values: array{enabled: bool, message: string, allowlist: string, starts: string, ends: string}}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $message = trim((string) $input['message']);
        $allowlist = trim((string) $input['allowlist']);
        $starts = $this->normalise((string) $input['starts']);
        $ends = $this->normalise((string) $input['ends']);

        if (mb_strlen($message) > self::MAX_MESSAGE) {
            $errors['message'] = 'Keep the message to ' . self::MAX_MESSAGE . ' characters or fewer.';
        }

        foreach (IpRangeMatcher::parseList($allowlist) as $rule) {
            if (!IpRangeMatcher::isValidRule($rule)) {
                $errors['allowlist'] = '"' . mb_substr($rule, 0, 60) . '" is not an address or a range such as 203.0.113.0/24.';
                break;
            }
        }

        foreach (['starts' => $starts, 'ends' => $ends] as $field => $value) {
            if ($value === false) {
                $errors[$field] = 'Use a date and time such as 2026-11-01 02:00.';
            }
        }

        if ($errors === []) {
            $from = $this->moment((string) $starts);
            $to = $this->moment((string) $ends);

            if ($from !== null && $to === null) {
                $errors['ends'] = 'A scheduled period needs an end time as well as a start.';
            } elseif ($from !== null && $to !== null && $to <= $from) {
                $errors['ends'] = 'The end must be after the start.';
            }
        }

        return [
            'errors' => $errors,
            'values' => [
                'enabled' => $input['enabled'],
                'message' => $message,
                'allowlist' => $allowlist,
                'starts' => is_string($starts) ? $starts : '',
                'ends' => is_string($ends) ? $ends : '',
            ],
        ];
    }

    /**
     * @param array{enabled: bool, message: string, allowlist: string, starts: string, ends: string} $values
     * @return list<string> the names of the settings that changed
     */
    public function save(array $values, User $actor, string $ipAddress): array
    {
        if (!$actor->can(Capability::ManageSite)) {
            return [];
        }

        $current = $this->current();
        $changes = [];

        foreach (['enabled', 'message', 'allowlist', 'starts', 'ends'] as $field) {
            if ($values[$field] !== $current[$field]) {
                $changes['maintenance.' . $field] = ['from' => $current[$field], 'to' => $values[$field]];
            }
        }

        if ($changes === []) {
            return [];
        }

        $this->database->transaction(function () use ($changes, $actor, $ipAddress): void {
            foreach ($changes as $setting => $change) {
                $this->config->setSetting($setting, $change['to']);
            }

            // The text of the message and the address list are not copied into the log.
            $logged = [];

            foreach ($changes as $setting => $change) {
                $logged[$setting] = in_array($setting, ['maintenance.message', 'maintenance.allowlist'], true) ? ['changed' => true] : $change;
            }

            $this->audit->record($actor->id, 'settings.maintenance.update', 'settings', 'maintenance', $logged, $ipAddress);
        });

        return array_keys($changes);
    }

    /**
     * The scheduled times are read in the server's time zone, the one the form tells the administrator about.
     */
    public function zone(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get());
    }

    private function text(string $name): string
    {
        $value = $this->config->setting($name, '');

        return is_string($value) ? $value : '';
    }

    private function moment(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, $this->zone());
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @return string|false|'' the value as "YYYY-MM-DD HH:MM", an empty string for none, or false when it is not a time
     */
    private function normalise(string $value): string|false
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match(self::TIME_FORMAT, $value) !== 1 || $this->moment($value) === null) {
            return false;
        }

        return str_replace('T', ' ', $value);
    }
}
