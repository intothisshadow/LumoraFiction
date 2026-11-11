<?php

/**
 * Finds and removes what a long-running archive no longer needs: old chapter versions, expired trash and tokens, accounts that were never confirmed, and unused tags and fandoms.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Plugins\Sweep;

use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\User;
use LumoraFiction\Services\AccountDeletionService;
use LumoraFiction\Services\AccountDeletionException;
use LumoraFiction\Services\AuditLogService;
use LumoraFiction\Services\ChapterService;
use LumoraFiction\Services\RetentionSettings;
use LumoraFiction\Services\StoryService;
use LumoraFiction\Services\TaxonomyException;
use LumoraFiction\Services\TaxonomyService;
use LumoraFiction\Services\UserService;

/**
 * Everything is removed through the services that normally own it (stories and chapters through their own purge, terms
 * through the classification service, accounts through account deletion), so cover files, links and hooks are handled
 * as they always are and nothing a story still references can go: the queries here only decide what is eligible. Each
 * pass is capped, so one request cannot run unbounded; run it again for more.
 */
final class Sweeper
{
    public const REVISIONS = 'revisions';

    public const TRASH = 'trash';

    public const TOKENS = 'tokens';

    public const ACCOUNTS = 'accounts';

    public const TERMS = 'terms';

    /** @var array<string, array{label: string, hint: string}> */
    public const TASKS = [
        self::REVISIONS => ['label' => 'Old chapter versions', 'hint' => 'Earlier versions of a chapter beyond the number kept (Settings > Retention). The newest ones stay.'],
        self::TRASH => ['label' => 'Expired trash', 'hint' => 'Stories and chapters that have been in the trash longer than the retention period. Does nothing while that period is 0 (keep until deleted).'],
        self::TOKENS => ['label' => 'Expired links', 'hint' => 'Password-reset and email-confirmation links that have run out. They can no longer be used.'],
        self::ACCOUNTS => ['label' => 'Never-confirmed accounts', 'hint' => 'Accounts that never confirmed their email address, past the age set below. Their personal details are removed, as when an account is deleted.'],
        self::TERMS => ['label' => 'Unused tags and fandoms', 'hint' => 'Reviewed tags and fandoms that no story uses, trashed stories included, and that have no characters or relationships of their own.'],
    ];

    /** The most one pass of one kind removes. */
    private const PASS_LIMIT = 5000;

    public function __construct(
        private readonly Database $database,
        private readonly Configuration $config,
        private readonly StoryService $stories,
        private readonly ChapterService $chapters,
        private readonly TaxonomyService $taxonomy,
        private readonly AccountDeletionService $deletion,
        private readonly UserService $users,
        private readonly AuditLogService $audit,
        private readonly SweepSettings $settings,
    ) {
    }

    /**
     * @return array<string, int> how many items each kind would remove now
     */
    public function counts(): array
    {
        return [
            self::REVISIONS => $this->countRevisions(),
            self::TRASH => $this->countTrash(),
            self::TOKENS => (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}user_tokens WHERE expires_at < :now', ['now' => date('Y-m-d H:i:s')]),
            self::ACCOUNTS => $this->countAccounts(),
            self::TERMS => $this->countTerms(),
        ];
    }

    public function total(): int
    {
        return array_sum($this->counts());
    }

    /**
     * A few of the items a kind would remove, as words an Administrator can recognise. Never an email address.
     *
     * @return list<string>
     */
    public function samples(string $task, int $limit = 8): array
    {
        $limit = max(1, min(20, $limit));

        return match ($task) {
            self::REVISIONS => array_map(
                static fn (array $row): string => 'Chapter “' . $row['title'] . '”: ' . (int) $row['extra'] . ' older versions to remove',
                $this->database->fetchAll(
                    'SELECT c.title AS title, COUNT(*) - :keep AS extra FROM {prefix}chapter_revisions r JOIN {prefix}chapters c ON c.id = r.chapter_id
                     GROUP BY r.chapter_id, c.title HAVING COUNT(*) > :keep2 ORDER BY extra DESC LIMIT ' . $limit,
                    ['keep' => $this->revisionsKept(), 'keep2' => $this->revisionsKept()],
                ),
            ),
            self::TRASH => array_map(
                static fn (array $row): string => ucfirst($row['kind']) . ' “' . $row['title'] . '”',
                $this->trashRows($limit),
            ),
            self::TOKENS => [],
            self::ACCOUNTS => array_map(
                static fn (array $row): string => $row['display_name'] . ' (registered ' . substr($row['created_at'], 0, 10) . ')',
                $this->accountRows($limit),
            ),
            self::TERMS => array_map(
                static fn (array $row): string => ucfirst($row['type']) . ' “' . $row['name'] . '”',
                $this->termRows($limit),
            ),
            default => [],
        };
    }

    /**
     * @param list<string> $tasks kinds from TASKS; anything else is ignored
     * @return array<string, int> how many items of each chosen kind were removed
     */
    public function run(array $tasks, User $actor, string $ipAddress): array
    {
        $removed = [];

        foreach (array_keys(self::TASKS) as $task) {
            if (!in_array($task, $tasks, true)) {
                continue;
            }

            $removed[$task] = match ($task) {
                self::REVISIONS => $this->removeRevisions(),
                self::TRASH => $this->removeTrash(),
                self::TOKENS => $this->database->execute('DELETE FROM {prefix}user_tokens WHERE expires_at < :now', ['now' => date('Y-m-d H:i:s')]),
                self::ACCOUNTS => $this->removeAccounts($actor, $ipAddress),
                self::TERMS => $this->removeTerms($actor, $ipAddress),
            };
        }

        $this->audit->record($actor->id, 'sweep.run', 'plugin', 'sweep', $removed, $ipAddress);

        return $removed;
    }

    // ---- old chapter versions ---------------------------------------------------------------------------

    private function revisionsKept(): int
    {
        return RetentionSettings::revisionsKept($this->config);
    }

    /** A version is old when at least as many newer ones of the same chapter exist as are kept. */
    private const OLD_REVISION = '(SELECT COUNT(*) FROM {prefix}chapter_revisions n WHERE n.chapter_id = r.chapter_id AND n.id > r.id) >= :keep';

    private function countRevisions(): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}chapter_revisions r WHERE ' . self::OLD_REVISION, ['keep' => $this->revisionsKept()]);
    }

    private function removeRevisions(): int
    {
        $removed = 0;

        while ($removed < self::PASS_LIMIT) {
            $ids = array_map(
                static fn (array $row): int => (int) $row['id'],
                $this->database->fetchAll('SELECT r.id FROM {prefix}chapter_revisions r WHERE ' . self::OLD_REVISION . ' LIMIT 1000', ['keep' => $this->revisionsKept()]),
            );

            if ($ids === []) {
                break;
            }

            $removed += $this->deleteIds('chapter_revisions', $ids);
        }

        return $removed;
    }

    // ---- expired trash ------------------------------------------------------------------------------------

    private function trashCutoff(): ?string
    {
        $days = RetentionSettings::trashDays($this->config);

        return $days < 1 ? null : date('Y-m-d H:i:s', time() - $days * 86400);
    }

    private function countTrash(): int
    {
        $cutoff = $this->trashCutoff();

        if ($cutoff === null) {
            return 0;
        }

        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}stories WHERE trashed_at IS NOT NULL AND trashed_at < :cutoff', ['cutoff' => $cutoff])
            + (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}chapters WHERE trashed_at IS NOT NULL AND trashed_at < :cutoff', ['cutoff' => $cutoff]);
    }

    /**
     * @return list<array{kind: string, title: string}>
     */
    private function trashRows(int $limit): array
    {
        $cutoff = $this->trashCutoff();

        if ($cutoff === null) {
            return [];
        }

        return $this->database->fetchAll(
            'SELECT kind, title FROM (
                SELECT \'story\' AS kind, title, trashed_at FROM {prefix}stories WHERE trashed_at IS NOT NULL AND trashed_at < :cutoff1
                UNION ALL
                SELECT \'chapter\' AS kind, title, trashed_at FROM {prefix}chapters WHERE trashed_at IS NOT NULL AND trashed_at < :cutoff2
            ) t ORDER BY trashed_at LIMIT ' . $limit,
            ['cutoff1' => $cutoff, 'cutoff2' => $cutoff],
        );
    }

    private function removeTrash(): int
    {
        $removed = 0;

        // Each call removes up to a hundred (stories) or two hundred (chapters), so loop until a call finds nothing.
        foreach ([fn (): int => $this->stories->purgeExpiredTrash(), fn (): int => $this->chapters->purgeExpiredTrash()] as $purge) {
            $pass = 0;

            while ($removed < self::PASS_LIMIT && ($pass = $purge()) > 0) {
                $removed += $pass;
            }
        }

        return $removed;
    }

    // ---- accounts that never confirmed ------------------------------------------------------------------

    private const UNCONFIRMED = "u.status = 'pending' AND u.email_verified_at IS NULL AND u.created_at < :cutoff
        AND NOT EXISTS (SELECT 1 FROM {prefix}stories s WHERE s.owner_id = u.id)";

    private function accountCutoff(): string
    {
        return date('Y-m-d H:i:s', time() - $this->settings->unconfirmedDays() * 86400);
    }

    private function countAccounts(): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}users u WHERE ' . self::UNCONFIRMED, ['cutoff' => $this->accountCutoff()]);
    }

    /**
     * @return list<array{id: int|string, display_name: string, created_at: string}>
     */
    private function accountRows(int $limit): array
    {
        return $this->database->fetchAll('SELECT u.id, u.display_name, u.created_at FROM {prefix}users u WHERE ' . self::UNCONFIRMED . ' ORDER BY u.created_at LIMIT ' . $limit, ['cutoff' => $this->accountCutoff()]);
    }

    private function removeAccounts(User $actor, string $ipAddress): int
    {
        $removed = 0;

        foreach ($this->accountRows(self::PASS_LIMIT) as $row) {
            $user = $this->users->findById((int) $row['id']);

            if ($user === null) {
                continue;
            }

            try {
                // The account owns no story (checked in the query), so there is nothing to keep or delete.
                $this->deletion->delete($actor, $user, $ipAddress, true);
                $removed++;
            } catch (AccountDeletionException) {
                // Left alone and counted again the next time.
            }
        }

        return $removed;
    }

    // ---- unused tags and fandoms --------------------------------------------------------------------------

    private const UNUSED_TERM = "t.type IN ('tag', 'fandom') AND t.reviewed = 1
        AND NOT EXISTS (SELECT 1 FROM {prefix}story_terms st WHERE st.term_id = t.id)
        AND NOT EXISTS (SELECT 1 FROM {prefix}terms c WHERE c.parent_id = t.id)";

    private function countTerms(): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}terms t WHERE ' . self::UNUSED_TERM);
    }

    /**
     * @return list<array{id: int|string, type: string, name: string}>
     */
    private function termRows(int $limit): array
    {
        return $this->database->fetchAll('SELECT t.id, t.type, t.name FROM {prefix}terms t WHERE ' . self::UNUSED_TERM . ' ORDER BY t.type, t.name LIMIT ' . $limit);
    }

    private function removeTerms(User $actor, string $ipAddress): int
    {
        $removed = 0;
        $byType = [];

        foreach ($this->termRows(self::PASS_LIMIT) as $row) {
            $byType[(string) $row['type']][] = (int) $row['id'];
        }

        foreach ($byType as $type => $ids) {
            $kind = TermType::tryFrom($type);

            if ($kind === null) {
                continue;
            }

            // The classification service checks each term again and skips one that has become used since.
            foreach (array_chunk($ids, TaxonomyService::BULK_LIMIT) as $chunk) {
                try {
                    $removed += $this->taxonomy->bulk($actor, $kind, 'delete', $chunk, $ipAddress)['changed'];
                } catch (TaxonomyException) {
                    // Left for the next pass.
                }
            }
        }

        return $removed;
    }

    // ---- shared --------------------------------------------------------------------------------------------

    /**
     * @param list<int> $ids
     */
    private function deleteIds(string $table, array $ids): int
    {
        $params = [];
        $marks = [];

        foreach ($ids as $index => $id) {
            $params['id' . $index] = $id;
            $marks[] = ':id' . $index;
        }

        return $this->database->execute('DELETE FROM {prefix}' . $table . ' WHERE id IN (' . implode(', ', $marks) . ')', $params);
    }
}
