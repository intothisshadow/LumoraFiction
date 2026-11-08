<?php

/**
 * The comment settings under Settings > Comments: whether comments are on, how a first comment is treated, and the limits.
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

use LumoraFiction\Core\Configuration;
use LumoraFiction\Models\User;

/**
 * Every value is read through a method that falls back to the default when the stored one is damaged, so a bad row can
 * never switch comments on for everyone or lock the edit window open.
 */
final class CommentSettings
{
    /** A first comment waits for approval; once one has been approved the person's later comments appear at once. */
    public const TRUST_FIRST_HELD = 'first_held';

    /** Every comment appears at once, subject to the spam guard. */
    public const TRUST_OPEN = 'open';

    public const MAX_EDIT_MINUTES = 1440;

    public const MAX_THRESHOLD = 50;

    public const DEFAULTS = [
        'comments.enabled' => true,
        'comments.trust' => self::TRUST_FIRST_HELD,
        'comments.author_toggle' => true,
        'comments.edit_minutes' => 15,
        'comments.report_threshold' => 3,
        'comments.replies' => true,
    ];

    public function __construct(
        private readonly Configuration $config,
        private readonly AuditLogService $audit,
    ) {
    }

    public function enabled(): bool
    {
        return $this->config->setting('comments.enabled', true) === true;
    }

    public function trust(): string
    {
        $value = $this->config->setting('comments.trust', self::TRUST_FIRST_HELD);

        return $value === self::TRUST_OPEN ? self::TRUST_OPEN : self::TRUST_FIRST_HELD;
    }

    /** Whether a person may answer a comment directly. Off keeps replies already made, shown in the flat list. */
    public function repliesAllowed(): bool
    {
        return $this->config->setting('comments.replies', true) === true;
    }

    public function authorMayToggle(): bool
    {
        return $this->config->setting('comments.author_toggle', true) === true;
    }

    /** Minutes a commenter may edit their own comment; 0 means never. */
    public function editMinutes(): int
    {
        $value = $this->config->setting('comments.edit_minutes', 15);

        return is_int($value) && $value >= 0 && $value <= self::MAX_EDIT_MINUTES ? $value : 15;
    }

    /** Reports after which a comment is hidden until a moderator looks at it. */
    public function reportThreshold(): int
    {
        $value = $this->config->setting('comments.report_threshold', 3);

        return is_int($value) && $value >= 1 && $value <= self::MAX_THRESHOLD ? $value : 3;
    }

    /**
     * @return array{enabled: bool, trust: string, author_toggle: bool, edit_minutes: int, report_threshold: int, replies: bool}
     */
    public function current(): array
    {
        return [
            'enabled' => $this->enabled(),
            'trust' => $this->trust(),
            'author_toggle' => $this->authorMayToggle(),
            'edit_minutes' => $this->editMinutes(),
            'report_threshold' => $this->reportThreshold(),
            'replies' => $this->repliesAllowed(),
        ];
    }

    /**
     * @param array{enabled: bool, trust: ?string, author_toggle: bool, edit_minutes: ?string, report_threshold: ?string, replies?: bool} $input
     * @return array{errors: array<string, string>, values: array{enabled: bool, trust: string, author_toggle: bool, edit_minutes: int, report_threshold: int, replies: bool}}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $trust = in_array($input['trust'], [self::TRUST_FIRST_HELD, self::TRUST_OPEN], true) ? (string) $input['trust'] : self::TRUST_FIRST_HELD;

        $minutes = trim((string) $input['edit_minutes']);

        if (preg_match('/^[0-9]{1,4}\z/', $minutes) !== 1 || (int) $minutes > self::MAX_EDIT_MINUTES) {
            $errors['edit_minutes'] = 'Enter a whole number of minutes from 0 to ' . self::MAX_EDIT_MINUTES . '. Use 0 to let nobody edit a comment.';
            $minutes = '15';
        }

        $threshold = trim((string) $input['report_threshold']);

        if (preg_match('/^[0-9]{1,2}\z/', $threshold) !== 1 || (int) $threshold < 1 || (int) $threshold > self::MAX_THRESHOLD) {
            $errors['report_threshold'] = 'Enter a whole number of reports from 1 to ' . self::MAX_THRESHOLD . '.';
            $threshold = '3';
        }

        return ['errors' => $errors, 'values' => [
            'enabled' => $input['enabled'],
            'trust' => $trust,
            'author_toggle' => $input['author_toggle'],
            'edit_minutes' => (int) $minutes,
            'report_threshold' => (int) $threshold,
            // Left out of an older form means unchanged, never switched off by accident.
            'replies' => $input['replies'] ?? $this->repliesAllowed(),
        ]];
    }

    /**
     * @param array{enabled: bool, trust: string, author_toggle: bool, edit_minutes: int, report_threshold: int, replies?: bool} $values already validated
     * @return list<string> the names of the settings that changed
     */
    public function save(array $values, User $actor, string $ipAddress = ''): array
    {
        $map = [
            'enabled' => 'comments.enabled',
            'trust' => 'comments.trust',
            'author_toggle' => 'comments.author_toggle',
            'edit_minutes' => 'comments.edit_minutes',
            'report_threshold' => 'comments.report_threshold',
            'replies' => 'comments.replies',
        ];
        $current = $this->current();
        $changed = [];

        foreach ($map as $key => $setting) {
            if (array_key_exists($key, $values) && $current[$key] !== $values[$key]) {
                $this->config->setSetting($setting, $values[$key]);
                $changed[] = $setting;
            }
        }

        if ($changed !== []) {
            $this->audit->record($actor->id, 'comments.settings', 'setting', 'comments', ['changed' => $changed], $ipAddress);
        }

        return $changed;
    }
}
