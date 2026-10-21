<?php

/**
 * The settings behind the sign-up and form protections.
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
use LumoraFiction\Core\Security\SignupRules;
use LumoraFiction\Models\User;

/**
 * Every protection that costs a visitor something is on with a mild default, and the two that need an outside party or
 * a question the administrator writes (Akismet and the challenge) are off. A limit of 0 turns that limit off.
 */
final class SpamSettings
{
    public const DEFAULTS = [
        'spam.min_seconds' => 3,
        // Every submission counts, valid or not, so a person fixing a typo must be able to send the form again soon.
        'spam.registration_gap_seconds' => 5,
        'spam.registration_ip_limit' => 10,
        'spam.registration_flood_limit' => 60,
        'spam.registration_pause_minutes' => 60,
        'spam.registration_paused_until' => 0,
        'spam.block_disposable' => true,
        'spam.domain_blocklist' => '',
        'spam.question' => '',
        'spam.answer' => '',
        'spam.akismet_enabled' => false,
        'spam.akismet_key' => '',
    ];

    /** Form field => setting name. */
    private const FIELDS = [
        'min_seconds' => 'spam.min_seconds',
        'gap_seconds' => 'spam.registration_gap_seconds',
        'ip_limit' => 'spam.registration_ip_limit',
        'flood_limit' => 'spam.registration_flood_limit',
        'pause_minutes' => 'spam.registration_pause_minutes',
        'block_disposable' => 'spam.block_disposable',
        'domain_blocklist' => 'spam.domain_blocklist',
        'question' => 'spam.question',
        'answer' => 'spam.answer',
        'akismet_enabled' => 'spam.akismet_enabled',
        'akismet_key' => 'spam.akismet_key',
        'retention_days' => 'security.log_retention_days',
    ];

    /** Values the audit log must not hold, so it records only that they changed. */
    private const SECRET = ['answer', 'akismet_key'];

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
        private readonly SecurityEventLog $events,
    ) {
    }

    /**
     * @return array{min_seconds: int, gap_seconds: int, ip_limit: int, flood_limit: int, pause_minutes: int, block_disposable: bool, domain_blocklist: string, question: string, answer: string, akismet_enabled: bool, akismet_key: string, retention_days: int}
     */
    public function current(): array
    {
        return [
            'min_seconds' => $this->number('spam.min_seconds', 0, 60),
            'gap_seconds' => $this->number('spam.registration_gap_seconds', 0, 600),
            'ip_limit' => $this->number('spam.registration_ip_limit', 0, 1000),
            'flood_limit' => $this->number('spam.registration_flood_limit', 0, 100000),
            'pause_minutes' => max(1, $this->number('spam.registration_pause_minutes', 1, 1440)),
            'block_disposable' => $this->config->setting('spam.block_disposable') !== false,
            'domain_blocklist' => $this->text('spam.domain_blocklist'),
            'question' => $this->text('spam.question'),
            'answer' => $this->text('spam.answer'),
            'akismet_enabled' => $this->config->setting('spam.akismet_enabled') === true,
            'akismet_key' => $this->text('spam.akismet_key'),
            'retention_days' => $this->events->retentionDays(),
        ];
    }

    public function signupRules(): SignupRules
    {
        $current = $this->current();

        return new SignupRules(SignupRules::parseDomains($current['domain_blocklist'])['domains'], $current['block_disposable'], $current['question'], $current['answer']);
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
            'min_seconds' => [0, 60, 'Enter a whole number of seconds from 0 to 60.'],
            'gap_seconds' => [0, 600, 'Enter a whole number of seconds from 0 to 600.'],
            'ip_limit' => [0, 1000, 'Enter a whole number from 0 to 1000.'],
            'flood_limit' => [0, 100000, 'Enter a whole number from 0 to 100000.'],
            'pause_minutes' => [1, 1440, 'Enter a whole number of minutes from 1 to 1440.'],
            'retention_days' => [1, 365, 'Enter a whole number of days from 1 to 365.'],
        ];

        foreach ($numbers as $field => [$low, $high, $message]) {
            $raw = trim((string) ($input[$field] ?? ''));

            if (preg_match('/^[0-9]{1,6}\z/', $raw) === 1 && (int) $raw >= $low && (int) $raw <= $high) {
                $values[$field] = (int) $raw;
            } else {
                $errors[$field] = $message;
                $values[$field] = $current[$field];
            }
        }

        $values['block_disposable'] = $input['block_disposable'] === true;
        $values['akismet_enabled'] = $input['akismet_enabled'] === true;

        $parsed = SignupRules::parseDomains(is_string($input['domain_blocklist'] ?? null) ? $input['domain_blocklist'] : '');
        $values['domain_blocklist'] = implode("\n", $parsed['domains']);

        if ($parsed['invalid'] !== []) {
            $errors['domain_blocklist'] = 'These are not domain names: ' . implode(', ', array_slice($parsed['invalid'], 0, 5)) . '. Write one domain per line, such as example.com.';
        } elseif (count($parsed['domains']) > 500) {
            $errors['domain_blocklist'] = 'The list can hold up to 500 domains.';
        }

        $question = trim((string) preg_replace('/\s+/u', ' ', (string) ($input['question'] ?? '')));
        $answer = trim((string) ($input['answer'] ?? ''));
        $values['question'] = mb_substr($question, 0, 200);
        $values['answer'] = mb_substr($answer, 0, 100);

        if (mb_strlen($question) > 200) {
            $errors['question'] = 'The question can be up to 200 characters.';
        } elseif (mb_strlen($answer) > 100) {
            $errors['answer'] = 'The answer can be up to 100 characters.';
        } elseif (($question === '') !== ($answer === '')) {
            // One without the other would either lock everyone out or ask a question that has no wrong answer.
            $errors[$question === '' ? 'question' : 'answer'] = 'Fill in both the question and its answer, or leave both empty.';
        }

        $key = trim((string) ($input['akismet_key'] ?? ''));
        $values['akismet_key'] = $key;

        if ($key !== '' && preg_match('/^[A-Za-z0-9]{6,64}\z/', $key) !== 1) {
            $errors['akismet_key'] = 'An Akismet key is letters and numbers only.';
        } elseif ($values['akismet_enabled'] === true && $key === '') {
            $errors['akismet_key'] = 'Enter your Akismet key to turn Akismet on.';
        }

        return ['errors' => $errors, 'values' => $values];
    }

    /**
     * Saves only what changed, and records the change in the same transaction. The audit log notes that a secret
     * changed but never what it changed to. Returns the names of the settings that changed.
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
                $changes[$setting] = in_array($field, self::SECRET, true) ? ['changed' => true] : ['from' => $current[$field], 'to' => $values[$field]];
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

            $this->audit->record($actor->id, 'settings.spam.update', 'settings', 'spam', $changes, $ipAddress);
        });

        return array_keys($changes);
    }

    private function number(string $name, int $low, int $high): int
    {
        $value = $this->config->setting($name);

        return is_int($value) ? max($low, min($high, $value)) : (int) (self::DEFAULTS[$name] ?? 0);
    }

    private function text(string $name): string
    {
        $value = $this->config->setting($name);

        return is_string($value) ? $value : '';
    }
}
