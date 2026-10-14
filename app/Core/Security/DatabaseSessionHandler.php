<?php

/**
 * Stores PHP session data in the database so cleanup needs no filesystem or cron job.
 *
 * @package LumoraFiction
 * @subpackage Security
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Security;

use LumoraFiction\Core\Database\Database;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;
use Throwable;

/**
 * Every method fails safe: a database error is logged and degrades to "no session for this
 * request" instead of crashing the page, because a session read is infrastructure and not a
 * security decision. Cleanup is driven by PHP's own gc() callback, which SessionManager forces on.
 * There is no row locking, so two simultaneous requests from one browser can overwrite each
 * other's session changes; nothing stored in a session is critical enough to need it.
 *
 * It also implements validateId(). Without it PHP's strict mode cannot tell a session identifier
 * the server issued from one a visitor made up, and would adopt the made-up one, which is exactly
 * what session fixation needs.
 */
final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private const MAX_ID_LENGTH = 128;

    public function __construct(private readonly Database $database)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        if (strlen($id) > self::MAX_ID_LENGTH) {
            return '';
        }

        try {
            $row = $this->database->fetchOne('SELECT data FROM {prefix}sessions WHERE id = :id', ['id' => $id]);
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return '';
        }

        return (string) ($row['data'] ?? '');
    }

    public function write(string $id, string $data): bool
    {
        if (strlen($id) > self::MAX_ID_LENGTH) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        try {
            // The data is bound twice because native prepared statements cannot reuse a named parameter.
            $this->database->execute(
                'INSERT INTO {prefix}sessions (id, data, last_activity) VALUES (:id, :data, :activity)
                 ON DUPLICATE KEY UPDATE data = :updated_data, last_activity = :updated_activity',
                ['id' => $id, 'data' => $data, 'activity' => $now, 'updated_data' => $data, 'updated_activity' => $now],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return false;
        }

        return true;
    }

    /**
     * True only for an identifier that has a stored session, so an unknown one is replaced by a new one.
     */
    public function validateId(string $id): bool
    {
        if ($id === '' || strlen($id) > self::MAX_ID_LENGTH) {
            return false;
        }

        try {
            return $this->database->fetchColumn('SELECT 1 FROM {prefix}sessions WHERE id = :id', ['id' => $id]) !== null;
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return false;
        }
    }

    /**
     * Called instead of write() when the session data did not change, so only the activity time moves.
     */
    public function updateTimestamp(string $id, string $data): bool
    {
        if (strlen($id) > self::MAX_ID_LENGTH) {
            return false;
        }

        try {
            $this->database->execute(
                'UPDATE {prefix}sessions SET last_activity = :activity WHERE id = :id',
                ['activity' => date('Y-m-d H:i:s'), 'id' => $id],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return false;
        }

        return true;
    }

    public function destroy(string $id): bool
    {
        try {
            $this->database->execute('DELETE FROM {prefix}sessions WHERE id = :id', ['id' => $id]);
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return false;
        }

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            return $this->database->execute(
                'DELETE FROM {prefix}sessions WHERE last_activity < :cutoff',
                ['cutoff' => date('Y-m-d H:i:s', time() - $max_lifetime)],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return false;
        }
    }

    private function logFailure(Throwable $exception): void
    {
        error_log('[DatabaseSessionHandler] ' . $exception::class . ': ' . $exception->getMessage());
    }
}
