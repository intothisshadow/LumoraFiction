<?php

/**
 * The archive-wide settings an administrator chooses: what is published, who may join, and what needs approval.
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
use LumoraFiction\Models\User;
use LumoraFiction\Models\UserRole;

/**
 * Turning a feature off hides its screens and fields without deleting the data behind it, so the
 * choice can be reversed. The defaults are the secure ones: sign-ups need a confirmed email, and a
 * new Author's first story waits for approval.
 */
final class ArchiveSettings
{
    public const DEFAULTS = [
        'archive.mode' => 'both',
        'publishing.hold_first_story' => true,
    ];

    public const MODES = [
        'both' => 'Fanfiction and original fiction',
        'fanfiction' => 'Fanfiction only',
        'original' => 'Original fiction only',
    ];

    /** Form field => setting name. */
    private const FIELDS = [
        'archive_mode' => 'archive.mode',
        'registration_enabled' => 'registration.enabled',
        'default_role' => 'registration.default_role',
        'email_verification' => 'registration.email_verification',
        'hold_first_story' => 'publishing.hold_first_story',
    ];

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * @return array{archive_mode: string, registration_enabled: bool, default_role: string, email_verification: bool, hold_first_story: bool}
     */
    public function current(): array
    {
        $mode = $this->config->setting('archive.mode');
        $role = $this->config->setting('registration.default_role');

        return [
            'archive_mode' => is_string($mode) && isset(self::MODES[$mode]) ? $mode : 'both',
            // Anything other than a real "true" counts as closed, matching how the registration code reads it.
            'registration_enabled' => $this->config->setting('registration.enabled') === true,
            'default_role' => is_string($role) && in_array($role, self::roleValues(), true) ? $role : UserRole::Reader->value,
            'email_verification' => $this->config->setting('registration.email_verification') !== false,
            'hold_first_story' => $this->config->setting('publishing.hold_first_story') !== false,
        ];
    }

    /**
     * @param array{archive_mode: ?string, default_role: ?string, registration_enabled: bool, email_verification: bool, hold_first_story: bool} $input
     * @return array{errors: array<string, string>, values: array{archive_mode: string, registration_enabled: bool, default_role: string, email_verification: bool, hold_first_story: bool}}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $current = $this->current();

        $mode = $input['archive_mode'];
        $role = $input['default_role'];

        if ($mode === null || !isset(self::MODES[$mode])) {
            $errors['archive_mode'] = 'Choose what the site will publish.';
            $mode = $current['archive_mode'];
        }

        if ($role === null || !in_array($role, self::roleValues(), true)) {
            $errors['default_role'] = 'Choose the role new accounts receive.';
            $role = $current['default_role'];
        }

        return [
            'errors' => $errors,
            'values' => [
                'archive_mode' => $mode,
                'registration_enabled' => $input['registration_enabled'],
                'default_role' => $role,
                'email_verification' => $input['email_verification'],
                'hold_first_story' => $input['hold_first_story'],
            ],
        ];
    }

    /**
     * Saves only what changed, and records the change in the same transaction so the two cannot
     * disagree. Returns the names of the settings that changed.
     *
     * @param array{archive_mode: string, registration_enabled: bool, default_role: string, email_verification: bool, hold_first_story: bool} $values
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

            $this->audit->record($actor->id, 'settings.archive.update', 'settings', 'archive', $changes, $ipAddress);
        });

        return array_keys($changes);
    }

    /**
     * @return list<string>
     */
    private static function roleValues(): array
    {
        return array_map(static fn (UserRole $role): string => $role->value, UserRole::selfServiceRoles());
    }
}
