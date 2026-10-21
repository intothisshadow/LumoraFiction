<?php

/**
 * The sign-in lockout and reverse-proxy settings.
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

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Security\IpRangeMatcher;
use LumoraFiction\Models\User;

/**
 * The defaults are the behaviour before this screen existed: five failed sign-ins lock an address out for fifteen minutes.
 * No proxy is trusted until an administrator lists one, because trusting the wrong address lets any visitor choose the
 * address the rate limits and logs record.
 */
final class SecuritySettings
{
    public const DEFAULTS = [
        'security.lockout_attempts' => 5,
        'security.lockout_minutes' => 15,
        'security.lockout_progressive' => true,
        'security.notify_owner' => true,
        'security.trusted_proxies' => '',
    ];

    /** A proxy list this long is a mistake, not a setup. */
    private const MAX_PROXY_RULES = 50;

    /** Narrower than these means a range far too wide to be one's own proxies, such as "all of the internet". */
    private const MIN_IPV4_PREFIX = 8;

    private const MIN_IPV6_PREFIX = 32;

    /** Form field => setting name. */
    private const FIELDS = [
        'lockout_attempts' => 'security.lockout_attempts',
        'lockout_minutes' => 'security.lockout_minutes',
        'lockout_progressive' => 'security.lockout_progressive',
        'notify_owner' => 'security.notify_owner',
        'trusted_proxies' => 'security.trusted_proxies',
    ];

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * @return array{lockout_attempts: int, lockout_minutes: int, lockout_progressive: bool, notify_owner: bool, trusted_proxies: string}
     */
    public function current(): array
    {
        return [
            'lockout_attempts' => $this->number('security.lockout_attempts', 3, 20),
            'lockout_minutes' => $this->number('security.lockout_minutes', 1, 1440),
            'lockout_progressive' => $this->config->setting('security.lockout_progressive') !== false,
            'notify_owner' => $this->config->setting('security.notify_owner') !== false,
            'trusted_proxies' => is_string($stored = $this->config->setting('security.trusted_proxies')) ? $stored : '',
        ];
    }

    /**
     * @param array<string, string|bool|null> $input the submitted form, by field name
     * @return array{errors: array<string, string>, values: array<string, int|string|bool>}
     */
    public function validate(array $input): array
    {
        $current = $this->current();
        $errors = [];
        $values = $current;

        $numbers = [
            'lockout_attempts' => [3, 20, 'Enter a whole number of attempts from 3 to 20.'],
            'lockout_minutes' => [1, 1440, 'Enter a whole number of minutes from 1 to 1440.'],
        ];

        foreach ($numbers as $field => [$low, $high, $message]) {
            $raw = trim((string) ($input[$field] ?? ''));

            if (preg_match('/^[0-9]{1,5}\z/', $raw) === 1 && (int) $raw >= $low && (int) $raw <= $high) {
                $values[$field] = (int) $raw;
            } else {
                $errors[$field] = $message;
            }
        }

        $values['lockout_progressive'] = $input['lockout_progressive'] === true;
        $values['notify_owner'] = $input['notify_owner'] === true;

        $rules = [];
        $problems = [];

        foreach (IpRangeMatcher::parseList(is_string($input['trusted_proxies'] ?? null) ? $input['trusted_proxies'] : '') as $rule) {
            $problem = $this->ruleProblem($rule);

            if ($problem !== null) {
                $problems[] = $problem;
            } elseif (!in_array($rule, $rules, true)) {
                $rules[] = $rule;
            }
        }

        $values['trusted_proxies'] = implode("\n", $rules);

        if ($problems !== []) {
            $errors['trusted_proxies'] = implode(' ', array_slice($problems, 0, 3));
        } elseif (count($rules) > self::MAX_PROXY_RULES) {
            $errors['trusted_proxies'] = 'List up to ' . self::MAX_PROXY_RULES . ' proxies.';
        }

        return ['errors' => $errors, 'values' => $values];
    }

    /**
     * Saves only what changed, and records the change in the same transaction. Returns the names of the settings that changed.
     *
     * @param array<string, int|string|bool> $values
     * @return list<string>
     */
    public function save(array $values, User $actor, string $ipAddress): array
    {
        $current = $this->current();
        $changes = [];

        foreach (self::FIELDS as $field => $setting) {
            if ($values[$field] !== $current[$field]) {
                $changes[$setting] = ['from' => $current[$field], 'to' => $values[$field]];
            }
        }

        if ($changes === []) {
            return [];
        }

        $this->database->transaction(function () use ($changes, $values, $actor, $ipAddress): void {
            foreach (self::FIELDS as $field => $setting) {
                if (isset($changes[$setting])) {
                    $this->config->setSetting($setting, $values[$field]);
                }
            }

            $this->audit->record($actor->id, 'settings.security.update', 'settings', 'security', $changes, $ipAddress);
        });

        return array_keys($changes);
    }

    private function ruleProblem(string $rule): ?string
    {
        $shown = mb_substr($rule, 0, 50);

        if (!IpRangeMatcher::isValidRule($rule)) {
            return '"' . $shown . '" is not an address or a range such as 10.0.0.0/8.';
        }

        $prefix = IpRangeMatcher::prefixLength($rule) ?? 0;

        if ($prefix < (IpRangeMatcher::isIpv4Rule($rule) ? self::MIN_IPV4_PREFIX : self::MIN_IPV6_PREFIX)) {
            return '"' . $shown . '" covers far too many addresses to be your proxies.';
        }

        return null;
    }

    private function number(string $name, int $low, int $high): int
    {
        $value = $this->config->setting($name);

        return is_int($value) ? max($low, min($high, $value)) : (int) (self::DEFAULTS[$name] ?? $low);
    }
}
