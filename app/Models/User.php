<?php

/**
 * An account, as read from the database.
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

use DateTimeImmutable;

final readonly class User
{
    public function __construct(
        public int $id,
        public string $loginName,
        public string $displayName,
        public string $slug,
        public string $email,
        public UserRole $role,
        public UserStatus $status,
        public ?DateTimeImmutable $emailVerifiedAt,
        public ?DateTimeImmutable $passwordChangedAt,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public function can(Capability $capability): bool
    {
        return $this->status === UserStatus::Active && $this->role->can($capability);
    }

    /**
     * The only fields that may appear on a public page, in a feed, or in any output a visitor can see.
     * The login name and email address are left out on purpose.
     *
     * @return array{display_name: string, slug: string}
     */
    public function publicProfile(): array
    {
        return ['display_name' => $this->displayName, 'slug' => $this->slug];
    }
}
