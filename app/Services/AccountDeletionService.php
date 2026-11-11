<?php

/**
 * Deletes an account's personal data, and either its stories or the link between them and the person.
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

use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Media\AvatarImageStore;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use LumoraFiction\Models\UserRole;
use LumoraFiction\Models\UserStatus;

/**
 * The account row is anonymised, not removed. Stories that are kept need an owner, and other tables keep the number
 * in columns that are deliberately not foreign keys, so a row that stays but holds nothing personal is the safe way to
 * keep them consistent. Nothing that identifies the person is left in it: login name, email, display name, password,
 * author text and picture are all replaced or cleared, and the sign-in and address-based lookups can no longer find it.
 */
final class AccountDeletionService
{
    public function __construct(
        private readonly Database $database,
        private readonly StoryService $stories,
        private readonly BookmarkService $bookmarks,
        private readonly ReadingProgressService $progress,
        private readonly StoryFollowService $follows,
        private readonly PrivacySettings $privacy,
        private readonly AuditLogService $audit,
        private readonly ?AvatarImageStore $avatars = null,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    /**
     * A person may delete their own account; an account manager may delete anyone's. The last active administrator can
     * never be deleted, so the site cannot lock itself out. $keep says whether the stories stay under a neutral name
     * (true) or go too (false); null follows the site setting.
     *
     * @throws AccountDeletionException
     */
    public function delete(User $actor, User $subject, string $ipAddress = '', ?bool $keep = null): void
    {
        if ($actor->id !== $subject->id && !$actor->can(Capability::ManageUsers)) {
            throw new AccountDeletionException('You may not do that.');
        }

        if ($subject->status === UserStatus::Deleted) {
            throw new AccountDeletionException('That account has already been deleted.');
        }

        $this->requireAnotherAdministrator($subject, false);

        // A member deciding about their own writing is asked; otherwise the site's setting applies.
        $keep ??= $this->privacy->keepsStories();
        $removedStories = $keep ? 0 : $this->stories->deleteAllByOwner($subject->id, $actor, $ipAddress);
        $avatar = (string) ($this->database->fetchColumn('SELECT avatar_file FROM {prefix}users WHERE id = :id', ['id' => $subject->id]) ?? '');
        $neutral = $this->privacy->neutralName();

        $this->database->transaction(function () use ($actor, $subject, $keep, $removedStories, $neutral, $ipAddress): void {
            // Checked again with the rows locked, so two administrators deleting each other cannot both succeed.
            $this->requireAnotherAdministrator($subject, true);

            $this->bookmarks->deleteForUser($subject->id);
            $this->progress->deleteForUser($subject->id);
            $this->follows->deleteForUser($subject->id);

            foreach (['notifications', 'user_tokens'] as $table) {
                $this->database->execute('DELETE FROM {prefix}' . $table . ' WHERE user_id = :id', ['id' => $subject->id]);
            }

            // A person's series and collections go with them, and so does anything they offered to someone else's collection.
            // The stories stay or go as chosen above; only the groupings are removed.
            $this->database->execute('DELETE ss FROM {prefix}series_stories ss JOIN {prefix}series sr ON sr.id = ss.series_id WHERE sr.owner_id = :id', ['id' => $subject->id]);
            $this->database->execute('DELETE FROM {prefix}series WHERE owner_id = :id', ['id' => $subject->id]);
            $this->database->execute('DELETE cs FROM {prefix}collection_stories cs JOIN {prefix}collections c ON c.id = cs.collection_id WHERE c.owner_id = :id', ['id' => $subject->id]);
            $this->database->execute('DELETE FROM {prefix}collections WHERE owner_id = :id', ['id' => $subject->id]);
            $this->database->execute('DELETE FROM {prefix}collection_stories WHERE added_by = :id', ['id' => $subject->id]);

            // What the person created stays, but no longer points at them.
            $this->database->execute('UPDATE {prefix}terms SET created_by = NULL WHERE created_by = :id', ['id' => $subject->id]);
            $this->database->execute('UPDATE {prefix}chapter_revisions SET created_by = NULL WHERE created_by = :id', ['id' => $subject->id]);
            $this->database->execute('UPDATE {prefix}pages SET edited_by = NULL WHERE edited_by = :id', ['id' => $subject->id]);

            // The log keeps what happened for accountability, but the address it came from is personal data.
            $this->database->execute('UPDATE {prefix}audit_log SET ip_address = NULL WHERE user_id = :id', ['id' => $subject->id]);

            $now = date('Y-m-d H:i:s');
            $this->database->execute(
                'UPDATE {prefix}users SET login_name = :login, display_name = :name, slug = :slug, email = :email, password_hash = :hash,
                 role = :role, status = :status, email_verified_at = NULL, password_changed_at = NULL, editor_mode = NULL, publishing_override = NULL,
                 reader_preferences = NULL, lockout_notified_at = NULL, about = NULL, avatar_file = \'\', updated_at = :updated WHERE id = :id',
                [
                    'login' => 'deleted-' . $subject->id . '-' . bin2hex(random_bytes(4)),
                    'name' => $neutral,
                    'slug' => 'deleted-' . $subject->id,
                    'email' => 'deleted-' . $subject->id . '@deleted.invalid',
                    // A hash of random bytes that nobody knows, so the row can never be signed in to.
                    'hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                    'role' => UserRole::Reader->value,
                    'status' => UserStatus::Deleted->value,
                    'updated' => $now,
                    'id' => $subject->id,
                ],
            );

            // The audit entry holds the account number and what happened to the stories, never a name or an address.
            $this->audit->record($actor->id, 'account.delete', 'user', (string) $subject->id, ['stories' => $keep ? 'kept' : 'deleted', 'stories_removed' => $removedStories, 'self' => $actor->id === $subject->id], $ipAddress);
        });

        if ($avatar !== '') {
            $this->avatars?->delete($avatar);
        }

        $this->hooks?->doAction('account_deleted', $subject->id);
    }

    /**
     * How many stories the account owns, trash included, so a member is only asked what to do with stories they have.
     */
    public function storyCount(User $subject): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}stories WHERE owner_id = :id', ['id' => $subject->id]);
    }

    /**
     * @throws AccountDeletionException
     */
    private function requireAnotherAdministrator(User $subject, bool $lock): void
    {
        if ($subject->role !== UserRole::Administrator || $subject->status !== UserStatus::Active) {
            return;
        }

        $ids = array_map('intval', array_column($this->database->fetchAll(
            'SELECT id FROM {prefix}users WHERE role = :role AND status = :status' . ($lock ? ' FOR UPDATE' : ''),
            ['role' => UserRole::Administrator->value, 'status' => UserStatus::Active->value],
        ), 'id'));

        if (array_values(array_diff($ids, [$subject->id])) === []) {
            throw new AccountDeletionException('The site must keep at least one active administrator, so this account cannot be deleted.');
        }
    }
}
