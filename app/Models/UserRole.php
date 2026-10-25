<?php

/**
 * An account's role and the capabilities that role grants.
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

/**
 * A signed-out visitor is a Guest, which is the absence of an account and so is not a case here.
 * Capabilities answer "may this role do this kind of thing"; whether someone may act on one
 * particular story (ownership) is decided separately by the code that loads the story.
 */
enum UserRole: string
{
    case Administrator = 'administrator';
    case Editor = 'editor';
    case Author = 'author';
    case Reader = 'reader';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function can(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::Administrator => Capability::cases(),
            self::Editor => [
                Capability::AccessAdmin,
                Capability::ModerateStories,
                Capability::EditAnyStory,
                Capability::WriteStories,
                Capability::PublishWithoutReview,
                Capability::Bookmark,
                Capability::PublishNews,
            ],
            self::Author => [Capability::AccessAdmin, Capability::WriteStories, Capability::Bookmark],
            self::Reader => [Capability::Bookmark],
        };
    }

    /**
     * Roles a visitor may be given by registering themselves; staff roles are only ever assigned by an administrator.
     *
     * @return list<self>
     */
    public static function selfServiceRoles(): array
    {
        return [self::Reader, self::Author];
    }
}
