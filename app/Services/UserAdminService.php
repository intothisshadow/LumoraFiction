<?php

/**
 * An administrator's changes to other people's accounts: role, suspension and publishing rights.
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

use LumoraFiction\Core\Admin\ColumnSort;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\PublishingOverride;
use LumoraFiction\Models\User;
use LumoraFiction\Models\UserRole;
use LumoraFiction\Models\UserStatus;

/**
 * The rules live here and nowhere else, so a screen, a plugin or a script cannot skip them: only someone who may
 * manage users can act, nobody changes their own role or status, and the site always keeps one active administrator.
 * The existing services still do the writing (and the withdrawal of stories), so their rules are not repeated.
 */
final class UserAdminService
{
    public function __construct(
        private readonly Database $database,
        private readonly UserService $users,
        private readonly StoryService $stories,
        private readonly AuditLogService $audit,
        private readonly Configuration $config,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    /**
     * @param array{search?: string, role?: string, status?: string} $filters
     * @return list<User>
     */
    public function list(User $actor, array $filters, int $limit, int $offset, ?ColumnSort $sort = null): array
    {
        return $actor->can(Capability::ManageUsers) ? $this->users->search($filters, $limit, $offset, $sort) : [];
    }

    /**
     * The sortable columns of the Users list. Newest first stays the default.
     */
    public static function sortFrom(?string $sort, ?string $direction): ColumnSort
    {
        return ColumnSort::resolve([
            'name' => ['sql' => 'display_name', 'first' => ColumnSort::ASCENDING],
            'email' => ['sql' => 'email', 'first' => ColumnSort::ASCENDING],
            'role' => ['sql' => 'role', 'first' => ColumnSort::ASCENDING],
            'status' => ['sql' => 'status', 'first' => ColumnSort::ASCENDING],
            'joined' => ['sql' => 'created_at', 'first' => ColumnSort::DESCENDING],
        ], 'joined', ColumnSort::DESCENDING, 'id', $sort, $direction);
    }

    /**
     * @param array{search?: string, role?: string, status?: string} $filters
     */
    public function count(User $actor, array $filters): int
    {
        return $actor->can(Capability::ManageUsers) ? $this->users->countMatching($filters) : 0;
    }

    /**
     * @throws UserAdminException
     */
    public function changeRole(User $actor, int $userId, UserRole $role, string $reason = '', string $ipAddress = ''): User
    {
        $reason = $this->checkedReason($reason);
        $target = $this->target($actor, $userId);

        if ($target->role === $role) {
            throw new UserAdminException('That person already has that role.');
        }

        $this->database->transaction(function () use ($actor, $target, $role, $reason, $ipAddress): void {
            // Locked first, so two administrators demoting each other at the same moment cannot both succeed.
            if ($target->role === UserRole::Administrator && $role !== UserRole::Administrator) {
                $this->requireAnotherActiveAdministrator($target);
            }

            $this->users->setRole($target->id, $role);

            // Publishing rights only mean something for an Author, so leaving the role clears them.
            if ($role !== UserRole::Author && $target->publishingOverride !== null) {
                $this->database->execute('UPDATE {prefix}users SET publishing_override = NULL WHERE id = :id', ['id' => $target->id]);
            }

            $this->audit->record($actor->id, 'user.role.change', 'user', (string) $target->id, ['from' => $target->role->value, 'to' => $role->value] + $this->reasonDetail($reason), $ipAddress);
        });

        $changed = $this->mustFind($target->id);
        $this->hooks?->doAction('user_role_changed', $changed, $target->role, $actor, $reason);

        return $changed;
    }

    /**
     * Takes the account's stories out of view first when asked, then suspends it. A suspended person is signed out on
     * their next request and cannot sign in or write. If taking the stories down fails part-way the account is not
     * suspended, and running it again carries on.
     *
     * @return int how many stories were taken out of view
     * @throws UserAdminException
     */
    public function suspend(User $actor, int $userId, bool $unpublishStories, string $reason = '', string $ipAddress = ''): int
    {
        $reason = $this->checkedReason($reason);
        $target = $this->target($actor, $userId);

        if ($target->status === UserStatus::Suspended) {
            throw new UserAdminException('That account is already suspended.');
        }

        $withdrawn = 0;

        if ($unpublishStories) {
            try {
                $withdrawn = $this->stories->unpublishAllByOwner($actor, $target->id, $reason, $ipAddress);
            } catch (StoryPermissionException | StoryValidationException) {
                throw new UserAdminException('Their stories could not be taken down, so the account was not suspended.');
            }
        }

        $this->database->transaction(function () use ($actor, $target, $withdrawn, $reason, $ipAddress): void {
            if ($target->role === UserRole::Administrator) {
                $this->requireAnotherActiveAdministrator($target);
            }

            $this->users->setStatus($target->id, UserStatus::Suspended);
            $this->audit->record($actor->id, 'user.suspend', 'user', (string) $target->id, ['from' => $target->status->value, 'stories_unpublished' => $withdrawn] + $this->reasonDetail($reason), $ipAddress);
        });

        $this->hooks?->doAction('user_status_changed', $this->mustFind($target->id), $target->status, $actor, $reason);

        return $withdrawn;
    }

    /**
     * Lets a suspended person sign in again, as active or, when their address still needs confirming, as pending.
     *
     * @throws UserAdminException
     */
    public function reinstate(User $actor, int $userId, string $reason = '', string $ipAddress = ''): User
    {
        $reason = $this->checkedReason($reason);
        $target = $this->target($actor, $userId);

        if ($target->status !== UserStatus::Suspended) {
            throw new UserAdminException('That account is not suspended.');
        }

        $status = $this->statusAfterReinstating($target);

        $this->database->transaction(function () use ($actor, $target, $status, $reason, $ipAddress): void {
            $this->users->setStatus($target->id, $status);
            $this->audit->record($actor->id, 'user.reinstate', 'user', (string) $target->id, ['to' => $status->value] + $this->reasonDetail($reason), $ipAddress);
        });

        $changed = $this->mustFind($target->id);
        $this->hooks?->doAction('user_status_changed', $changed, UserStatus::Suspended, $actor, $reason);

        return $changed;
    }

    /**
     * What reinstating this account would make it. Someone whose address was never confirmed goes back to waiting for
     * that when the site requires confirmation, so a suspension never becomes a way around it.
     */
    public function statusAfterReinstating(User $target): UserStatus
    {
        $required = $this->config->setting('registration.email_verification', true) === true;

        return $required && $target->emailVerifiedAt === null ? UserStatus::Pending : UserStatus::Active;
    }

    /**
     * @throws UserAdminException
     */
    public function setPublishingRights(User $actor, int $userId, ?PublishingOverride $override, string $ipAddress = ''): User
    {
        $target = $this->target($actor, $userId);

        if ($target->role !== UserRole::Author) {
            throw new UserAdminException('Only an author can be given publishing rights.');
        }

        if ($target->publishingOverride === $override) {
            throw new UserAdminException('That is already how this author publishes.');
        }

        try {
            $this->stories->setPublishingOverride($actor, $target->id, $override, $ipAddress);
        } catch (StoryPermissionException $exception) {
            throw new UserAdminException($exception->getMessage());
        }

        $changed = $this->mustFind($target->id);
        $this->hooks?->doAction('user_publishing_override_changed', $changed, $target->publishingOverride, $actor);

        return $changed;
    }

    /**
     * The account an administrator may change: someone else's, that exists.
     *
     * @throws UserAdminException
     */
    private function target(User $actor, int $userId): User
    {
        if (!$actor->can(Capability::ManageUsers)) {
            throw new UserAdminException('You may not do that.');
        }

        if ($actor->id === $userId) {
            throw new UserAdminException('You cannot change your own role or status here. Another administrator can do it for you.');
        }

        return $this->users->findById($userId) ?? throw new UserAdminException('That account does not exist.');
    }

    /**
     * Run inside a transaction. Locks every active administrator, then refuses when the target is the only one.
     *
     * @throws UserAdminException
     */
    private function requireAnotherActiveAdministrator(User $target): void
    {
        $ids = array_map('intval', array_column($this->database->fetchAll(
            'SELECT id FROM {prefix}users WHERE role = :role AND status = :status FOR UPDATE',
            ['role' => UserRole::Administrator->value, 'status' => UserStatus::Active->value],
        ), 'id'));

        if (array_values(array_diff($ids, [$target->id])) === []) {
            throw new UserAdminException('The site must keep at least one active administrator.');
        }
    }

    /**
     * @throws UserAdminException
     */
    private function checkedReason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) > StoryService::REASON_MAX) {
            throw new UserAdminException('The reason is too long. Keep it under ' . number_format(StoryService::REASON_MAX) . ' characters.');
        }

        return $reason;
    }

    /**
     * @return array<string, string>
     */
    private function reasonDetail(string $reason): array
    {
        return $reason === '' ? [] : ['reason' => $reason];
    }

    private function mustFind(int $userId): User
    {
        return $this->users->findById($userId) ?? throw new UserAdminException('That account does not exist.');
    }
}
