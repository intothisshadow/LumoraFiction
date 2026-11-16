<?php

/**
 * The site-wide rule about who may publish stories, applied to every account whatever its stored role.
 *
 * @package LumoraFiction
 * @subpackage Security
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Security;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\UserRole;

/**
 * The mode is read once at boot and held here because `User::can()` is asked from services, templates and menus that
 * have no configuration to hand; one rule held in one place cannot disagree with itself. An account's stored role is
 * never rewritten: in "staff" mode an Author simply has the capabilities of a Reader until the setting changes back.
 */
final class PublishingPolicy
{
    public const ANYONE = 'anyone';

    public const APPROVED = 'approved';

    public const STAFF = 'staff';

    public const SETTING = 'publishing.who';

    public const DEFAULTS = [self::SETTING => self::ANYONE];

    public const MODES = [
        self::ANYONE => 'Anyone with an Author account',
        self::APPROVED => 'Only Authors an administrator has approved',
        self::STAFF => 'Only staff (Administrators and Editors)',
    ];

    private static string $mode = self::ANYONE;

    /** The mode in the saved settings, falling back to today's behaviour for a missing or damaged value. */
    public static function modeOf(Configuration $config): string
    {
        $mode = $config->setting(self::SETTING);

        return is_string($mode) && isset(self::MODES[$mode]) ? $mode : self::ANYONE;
    }

    public static function use(string $mode): void
    {
        self::$mode = isset(self::MODES[$mode]) ? $mode : self::ANYONE;
    }

    public static function mode(): string
    {
        return self::$mode;
    }

    /**
     * The role whose capabilities an account of this stored role really has right now.
     */
    public static function effectiveRole(UserRole $role): UserRole
    {
        return self::$mode === self::STAFF && $role === UserRole::Author ? UserRole::Reader : $role;
    }

    /** Whether an account of this stored role can write stories under the mode in force. */
    public static function allowsWriting(UserRole $role): bool
    {
        return self::effectiveRole($role)->can(Capability::WriteStories);
    }

    /** Registering never makes an Author unless anyone with an Author account may publish. */
    public static function registrationMakesAuthors(string $mode): bool
    {
        return $mode === self::ANYONE;
    }
}
