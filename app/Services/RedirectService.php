<?php

/**
 * Keeps the list of redirects from one address of the site to another, and answers which one an address has.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Admin\BulkResult;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;
use PDOException;

/**
 * Sources are paths on this site without the install folder, so moving the site to another folder keeps them valid. A
 * target is another path on this site or a full web address. Automatic redirects, made when an address changes, are
 * marked, and can be deleted like any other. A redirect only answers where the site has nothing to show (see
 * Kernel::redirectFor()), so it can never hide a live page, and it can never loop, because every one is checked against
 * the chain it would join before it is saved.
 */
final class RedirectService
{
    public const SOURCE_MAX = 400;

    public const TARGET_MAX = 500;

    /** Only permanent and temporary: anything else changes what a browser or a crawler does in ways nobody asked for here. */
    public const STATUSES = [301 => 'Permanent (301)', 302 => 'Temporary (302)'];

    public const PAGE_SIZE = 50;

    /** A chain longer than this is treated as a loop; a real one is never close. */
    private const MAX_HOPS = 10;

    /** Addresses that must keep answering for themselves, whatever the list says. */
    private const RESERVED = ['/admin', '/account', '/install'];

    public function __construct(
        private readonly Database $database,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * The redirect for a path, or null. A missing table (the migration has not run yet) reads as no redirects, so a
     * site with new files and an old database still answers.
     *
     * @return array{target: string, status: int}|null
     */
    public function find(string $path): ?array
    {
        try {
            $row = $this->database->fetchOne('SELECT target, status FROM {prefix}redirects WHERE source = :source', ['source' => $path]);
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1146) {
                return null;
            }

            throw $exception;
        }

        return $row === null ? null : ['target' => (string) $row['target'], 'status' => (int) $row['status']];
    }

    /**
     * @return list<array{id: int, source: string, target: string, status: int, automatic: bool, created_at: string}>
     */
    public function list(int $limit = self::PAGE_SIZE, int $offset = 0): array
    {
        $rows = $this->database->fetchAll(
            'SELECT id, source, target, status, automatic, created_at FROM {prefix}redirects ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'source' => (string) $row['source'],
            'target' => (string) $row['target'],
            'status' => (int) $row['status'],
            'automatic' => (int) $row['automatic'] === 1,
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    /**
     * @return array{source: string, target: string, status: int}|null
     */
    public function findById(int $id): ?array
    {
        $row = $this->database->fetchOne('SELECT source, target, status FROM {prefix}redirects WHERE id = :id', ['id' => $id]);

        return $row === null ? null : ['source' => (string) $row['source'], 'target' => (string) $row['target'], 'status' => (int) $row['status']];
    }

    public function count(): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}redirects');
    }

    /**
     * @throws RedirectException
     */
    public function add(User $actor, string $source, string $target, int $status = 301, string $ipAddress = ''): int
    {
        if (!$actor->can(Capability::ManageSite)) {
            throw new RedirectException('You may not change redirects.');
        }

        if (!isset(self::STATUSES[$status])) {
            throw new RedirectException('Choose permanent or temporary.', 'status');
        }

        $source = $this->cleanSource($source);
        $target = $this->cleanTarget($target);

        if ($this->find($source) !== null) {
            throw new RedirectException('There is already a redirect from that address. Delete it first to replace it.', 'source');
        }

        $this->assertNoLoop($source, $target);

        $id = (int) $this->database->transaction(function () use ($actor, $source, $target, $status, $ipAddress): int {
            $id = (int) $this->database->insertGetId(
                'INSERT INTO {prefix}redirects (source, target, status, automatic, created_by, created_at) VALUES (:source, :target, :status, 0, :creator, :created)',
                ['source' => $source, 'target' => $target, 'status' => $status, 'creator' => $actor->id, 'created' => date('Y-m-d H:i:s')],
            );
            $this->audit->record($actor->id, 'redirect.create', 'redirect', (string) $id, ['from' => $source, 'to' => $target, 'status' => $status], $ipAddress);

            return $id;
        });

        return $id;
    }

    public function delete(User $actor, int $id, string $ipAddress = ''): bool
    {
        if (!$actor->can(Capability::ManageSite)) {
            return false;
        }

        $row = $this->database->fetchOne('SELECT source, target FROM {prefix}redirects WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            return false;
        }

        $this->database->transaction(function () use ($actor, $id, $row, $ipAddress): void {
            $this->database->execute('DELETE FROM {prefix}redirects WHERE id = :id', ['id' => $id]);
            $this->audit->record($actor->id, 'redirect.delete', 'redirect', (string) $id, ['from' => (string) $row['source'], 'to' => (string) $row['target']], $ipAddress);
        });

        return true;
    }

    /**
     * Makes a redirect permanent or temporary. The address it answers and the one it sends to stay as they are.
     *
     * @return bool false when the person may not change redirects, the kind is not offered or the redirect is not there
     */
    public function changeStatus(User $actor, int $id, int $status, string $ipAddress = ''): bool
    {
        if (!$actor->can(Capability::ManageSite) || !isset(self::STATUSES[$status])) {
            return false;
        }

        $row = $this->database->fetchOne('SELECT source, target, status FROM {prefix}redirects WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            return false;
        }

        if ((int) $row['status'] !== $status) {
            $this->database->transaction(function () use ($actor, $id, $row, $status, $ipAddress): void {
                $this->database->execute('UPDATE {prefix}redirects SET status = :status WHERE id = :id', ['status' => $status, 'id' => $id]);
                $this->audit->record($actor->id, 'redirect.status', 'redirect', (string) $id, ['from' => (string) $row['source'], 'status' => ['from' => (int) $row['status'], 'to' => $status]], $ipAddress);
            });
        }

        return true;
    }

    /** The actions bulk() accepts. */
    public const BULK_ACTIONS = ['delete', 'permanent', 'temporary'];

    /**
     * Deletes several redirects, or makes them all permanent or all temporary, one at a time through the single actions.
     *
     * @param list<int> $ids at most BulkResult::LIMIT are handled
     * @throws RedirectException when the person may not change redirects or the action is not one of BULK_ACTIONS
     */
    public function bulk(User $actor, string $action, array $ids, string $ipAddress = ''): BulkResult
    {
        if (!$actor->can(Capability::ManageSite)) {
            throw new RedirectException('You may not change redirects.');
        }

        if (!in_array($action, self::BULK_ACTIONS, true)) {
            throw new RedirectException('Choose what to do with the selected redirects.', 'bulk_action');
        }

        $result = new BulkResult();

        foreach (array_slice(array_values(array_unique($ids)), 0, BulkResult::LIMIT) as $id) {
            $changed = match ($action) {
                'delete' => $this->delete($actor, $id, $ipAddress),
                'permanent' => $this->changeStatus($actor, $id, 301, $ipAddress),
                'temporary' => $this->changeStatus($actor, $id, 302, $ipAddress),
            };

            $changed ? $result->done() : $result->skip('Some of the redirects are no longer there.');
        }

        if ($result->doneCount() > 0) {
            $this->audit->record($actor->id, 'redirect.bulk', 'redirect', '', ['action' => $action, 'done' => $result->doneCount(), 'skipped' => $result->skippedCount()], $ipAddress);
        }

        return $result;
    }

    /**
     * Sends the old address of something that moved to its new one for good. Redirects that already pointed at the old
     * address are repointed, so a visitor is never sent through a chain, and a redirect that started at the new address
     * is dropped, because the new address is live again. Does nothing when the two are the same or the move would loop.
     * Called by the services that rename things, with no person to credit, so it writes no audit entry of its own.
     */
    public function remember(string $oldPath, string $newPath): void
    {
        try {
            $old = $this->cleanSource($oldPath);
            $new = $this->cleanTarget($newPath);
        } catch (RedirectException) {
            return;
        }

        if ($old === $new) {
            return;
        }

        try {
            $this->database->transaction(function () use ($old, $new): void {
                $this->database->execute('DELETE FROM {prefix}redirects WHERE source = :source', ['source' => $new]);
                $this->database->execute('UPDATE {prefix}redirects SET target = :new WHERE target = :old', ['new' => $new, 'old' => $old]);

                // Repointing may have made a redirect point at itself; those are the ones that would have looped.
                $this->database->execute('DELETE FROM {prefix}redirects WHERE source = target');

                $this->assertNoLoop($old, $new);
                $this->database->execute(
                    'INSERT INTO {prefix}redirects (source, target, status, automatic, created_by, created_at) VALUES (:source, :target, 301, 1, NULL, :created)
                     ON DUPLICATE KEY UPDATE target = VALUES(target), status = 301, automatic = 1',
                    ['source' => $old, 'target' => $new, 'created' => date('Y-m-d H:i:s')],
                );
            });
        } catch (RedirectException) {
            // A loop would have been the result; the old address simply stays unredirected.
            return;
        } catch (PDOException $exception) {
            // The table is not there until the migration runs, and a rename must never fail because of that.
            if (($exception->errorInfo[1] ?? null) !== 1146) {
                throw $exception;
            }
        }
    }

    /**
     * @throws RedirectException
     */
    public function cleanSource(string $raw): string
    {
        $path = trim($raw);

        // An administrator may paste a whole address of this site; only its path matters, and the query is not part of an address here.
        if (preg_match('#^https?://[^/]+(/.*)?\z#i', $path, $matches) === 1) {
            $path = $matches[1] ?? '/';
        }

        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '?') || str_contains($path, '#')) {
            throw new RedirectException('Enter a path on this site that starts with a slash, such as /old-page.', 'source');
        }

        if (preg_match('/[\x00-\x20\x7f\\\\]/', $path) === 1 || !mb_check_encoding($path, 'UTF-8') || strlen($path) > self::SOURCE_MAX) {
            throw new RedirectException('That address has characters that cannot be used, or is too long.', 'source');
        }

        $path = $path === '/' ? '/' : rtrim($path, '/');

        if ($path === '/') {
            throw new RedirectException('The home page cannot be redirected.', 'source');
        }

        foreach (self::RESERVED as $reserved) {
            if ($path === $reserved || str_starts_with($path, $reserved . '/')) {
                throw new RedirectException('Addresses under ' . $reserved . ' cannot be redirected.', 'source');
            }
        }

        return $path;
    }

    /**
     * @throws RedirectException
     */
    public function cleanTarget(string $raw): string
    {
        $target = trim($raw);

        if ($target === '' || strlen($target) > self::TARGET_MAX || preg_match('/[\x00-\x20\x7f\\\\]/', $target) === 1 || !mb_check_encoding($target, 'UTF-8')) {
            throw new RedirectException('Enter where it should go: a path on this site or a full web address.', 'target');
        }

        if ($target[0] === '/' && !str_starts_with($target, '//')) {
            return $target === '/' ? '/' : rtrim($target, '/');
        }

        $parts = parse_url($target);

        // Only http and https, and never an address with a login in it, which is how a link is made to look like another site.
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RedirectException('Enter where it should go: a path on this site or a full web address starting with http:// or https://.', 'target');
        }

        return $target;
    }

    /**
     * Follows the chain a new redirect would join. A target that is not a path on this site ends the chain.
     *
     * @throws RedirectException
     */
    private function assertNoLoop(string $source, string $target): void
    {
        $seen = [$source => true];
        $next = $target;

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            if ($next[0] !== '/') {
                return;
            }

            if (isset($seen[$next])) {
                throw new RedirectException('That would send visitors round in a circle.', 'target');
            }

            $seen[$next] = true;
            $found = $this->find($next);

            if ($found === null) {
                return;
            }

            $next = $found['target'];
        }

        throw new RedirectException('That would send visitors round in a circle.', 'target');
    }
}
