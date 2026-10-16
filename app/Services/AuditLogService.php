<?php

/**
 * Records who changed what, and reads the record back.
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

use DateTimeImmutable;
use InvalidArgumentException;
use LumoraFiction\Core\Database\Database;

/**
 * Actions are dotted lower-case names such as "settings.archive.update". A failure to write is not
 * swallowed: an administrative change that cannot be recorded should not quietly go ahead unrecorded.
 */
final class AuditLogService
{
    private const MAX_DETAILS_BYTES = 8000;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @param array<string, mixed> $details what changed; never put passwords, tokens, or login names here
     */
    public function record(?int $userId, string $action, ?string $targetType = null, ?string $targetId = null, array $details = [], string $ipAddress = ''): void
    {
        if (preg_match('/^[a-z][a-z0-9_.]{0,99}\z/', $action) !== 1) {
            throw new InvalidArgumentException('Invalid audit action.');
        }

        $encoded = $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        // A runaway details value must not be able to fill the table or fail the insert.
        if (is_string($encoded) && strlen($encoded) > self::MAX_DETAILS_BYTES) {
            $encoded = json_encode(['truncated' => true]);
        }

        $this->database->execute(
            'INSERT INTO {prefix}audit_log (user_id, action, target_type, target_id, details, ip_address, created_at)
             VALUES (:user, :action, :type, :target, :details, :ip, :created)',
            [
                'user' => $userId,
                'action' => $action,
                'type' => $targetType === null ? null : substr($targetType, 0, 50),
                'target' => $targetId === null ? null : substr($targetId, 0, 64),
                'details' => $encoded === false ? null : $encoded,
                'ip' => $ipAddress === '' ? null : substr($ipAddress, 0, 45),
                'created' => date('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * The newest entries first. The person is shown by display name only: the login name stays private.
     *
     * @return list<array{action: string, actor: string|null, target_type: string|null, target_id: string|null, details: array<string, mixed>, at: DateTimeImmutable}>
     */
    public function recent(int $limit = 10): array
    {
        $rows = $this->database->fetchAll(
            'SELECT a.action, a.target_type, a.target_id, a.details, a.created_at, u.display_name
             FROM {prefix}audit_log a LEFT JOIN {prefix}users u ON u.id = a.user_id
             ORDER BY a.id DESC LIMIT ' . max(1, min(200, $limit)),
        );

        return array_map(static function (array $row): array {
            $details = is_string($row['details']) ? json_decode($row['details'], true) : null;

            return [
                'action' => (string) $row['action'],
                'actor' => $row['display_name'] === null ? null : (string) $row['display_name'],
                'target_type' => $row['target_type'] === null ? null : (string) $row['target_type'],
                'target_id' => $row['target_id'] === null ? null : (string) $row['target_id'],
                'details' => is_array($details) ? $details : [],
                'at' => new DateTimeImmutable((string) $row['created_at']),
            ];
        }, $rows);
    }
}
