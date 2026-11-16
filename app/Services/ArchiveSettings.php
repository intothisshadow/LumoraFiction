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
use LumoraFiction\Core\Security\PublishingPolicy;
use LumoraFiction\Models\User;
use LumoraFiction\Models\UserRole;
use LumoraFiction\Models\UserStatus;

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
        'reading.require_signin' => false,
        'taxonomy.writer_tags' => true,
        PublishingPolicy::SETTING => PublishingPolicy::ANYONE,
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
        'require_signin' => 'reading.require_signin',
        'writer_tags' => 'taxonomy.writer_tags',
        'who_publishes' => PublishingPolicy::SETTING,
    ];

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * The archive mode, falling back to "both" for a missing or damaged value. The one place that reads it, so every
     * rule that depends on the mode agrees on what it is.
     */
    public static function modeOf(Configuration $config): string
    {
        $mode = $config->setting('archive.mode');

        return is_string($mode) && isset(self::MODES[$mode]) ? $mode : 'both';
    }

    /**
     * @return array{archive_mode: string, registration_enabled: bool, default_role: string, email_verification: bool, hold_first_story: bool, require_signin: bool, writer_tags: bool, who_publishes: string}
     */
    public function current(): array
    {
        $role = $this->config->setting('registration.default_role');
        $who = PublishingPolicy::modeOf($this->config);

        return [
            'archive_mode' => self::modeOf($this->config),
            // Anything other than a real "true" counts as closed, matching how the registration code reads it.
            'registration_enabled' => $this->config->setting('registration.enabled') === true,
            // A stored Author that the publishing rule no longer allows is shown (and acted on) as Reader.
            'default_role' => is_string($role) && in_array($role, self::roleValues($who), true) ? $role : UserRole::Reader->value,
            'who_publishes' => $who,
            'email_verification' => $this->config->setting('registration.email_verification') !== false,
            'hold_first_story' => $this->config->setting('publishing.hold_first_story') !== false,
            // Closed only by a real "true", so a missing or damaged value leaves the archive readable.
            'require_signin' => $this->config->setting('reading.require_signin') === true,
            // Open unless switched off for real, so a missing value keeps today's behaviour.
            'writer_tags' => $this->config->setting('taxonomy.writer_tags') !== false,
        ];
    }

    /**
     * @param array{archive_mode: ?string, default_role: ?string, registration_enabled: bool, email_verification: bool, hold_first_story: bool, require_signin: bool, writer_tags: bool, who_publishes: string} $input
     * @return array{errors: array<string, string>, notices: list<string>, values: array{archive_mode: string, registration_enabled: bool, default_role: string, email_verification: bool, hold_first_story: bool, require_signin: bool, writer_tags: bool, who_publishes: string}}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $notices = [];
        $current = $this->current();

        $mode = $input['archive_mode'];
        $role = $input['default_role'];
        // A form or file without the field leaves the rule as it is.
        $who = array_key_exists('who_publishes', $input) ? $input['who_publishes'] : $current['who_publishes'];

        if ($mode === null || !isset(self::MODES[$mode])) {
            $errors['archive_mode'] = 'Choose what the site will publish.';
            $mode = $current['archive_mode'];
        }

        if ($who === null || !isset(PublishingPolicy::MODES[$who])) {
            $errors['who_publishes'] = 'Choose who can publish stories.';
            $who = $current['who_publishes'];
        }

        if ($role === null || !in_array($role, self::roleValues(), true)) {
            $errors['default_role'] = 'Choose the role new accounts receive.';
            $role = $current['default_role'];
        } elseif (!in_array($role, self::roleValues($who), true)) {
            // Registering must never make someone who could not publish an Author, so the choice becomes Reader and says so.
            $role = UserRole::Reader->value;
            $notices[] = 'New accounts become Readers, because only ' . ($who === PublishingPolicy::STAFF ? 'staff' : 'Authors an administrator has approved') . ' can publish on this site.';
        }

        return [
            'errors' => $errors,
            'notices' => $notices,
            'values' => [
                'who_publishes' => $who,
                'archive_mode' => $mode,
                'registration_enabled' => $input['registration_enabled'],
                'default_role' => $role,
                'email_verification' => $input['email_verification'],
                'hold_first_story' => $input['hold_first_story'],
                'require_signin' => $input['require_signin'],
                'writer_tags' => $input['writer_tags'],
            ],
        ];
    }

    /**
     * Saves only what changed, and records the change in the same transaction so the two cannot
     * disagree. Returns the names of the settings that changed.
     *
     * @param array{archive_mode: string, registration_enabled: bool, default_role: string, email_verification: bool, hold_first_story: bool, require_signin: bool, writer_tags: bool, who_publishes: string} $values
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

        // Takes effect for this request too, so the page the administrator lands on already reflects it.
        PublishingPolicy::use(PublishingPolicy::modeOf($this->config));

        return array_keys($changes);
    }

    /**
     * What switching to "only staff" would change, for the administrator to read before saving: the Author accounts that
     * would become reader-only and the published stories they own (which stay online).
     *
     * @return array{authors: int, stories: int}
     */
    public function staffImpact(): array
    {
        return [
            'authors' => (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}users WHERE role = :role AND status = :status', ['role' => UserRole::Author->value, 'status' => UserStatus::Active->value]),
            'stories' => (int) $this->database->fetchColumn(
                'SELECT COUNT(*) FROM {prefix}stories s JOIN {prefix}users u ON u.id = s.owner_id WHERE u.role = :role AND ' . StoryVisibility::publicCondition('s'),
                ['role' => UserRole::Author->value],
            ),
        ];
    }

    /**
     * The roles registering can give under a publishing rule: only "anyone" lets a new account become an Author.
     *
     * @return list<string>
     */
    public static function roleValues(string $who = PublishingPolicy::ANYONE): array
    {
        $roles = PublishingPolicy::registrationMakesAuthors($who) ? UserRole::selfServiceRoles() : [UserRole::Reader];

        return array_map(static fn (UserRole $role): string => $role->value, $roles);
    }
}
