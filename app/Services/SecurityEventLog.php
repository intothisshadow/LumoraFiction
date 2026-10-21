<?php

/**
 * The record of what the protections blocked, trimmed by age.
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
use Throwable;

/**
 * Writing never throws: a logging problem must not break the request being logged, and a request that was just
 * refused has already been answered. Only an event name, an address and a short note are kept, never anything a
 * visitor typed, so the log cannot become a store of private data. Control characters are removed from every value
 * so a hostile request cannot forge a second line.
 */
final class SecurityEventLog
{
    public const LOGIN_LOCKOUT = 'login_lockout';

    public const REGISTRATION_RATE_LIMITED = 'registration_rate_limited';

    public const REGISTRATION_PAUSED = 'registration_paused';

    public const REGISTRATION_BLOCKED_EMAIL = 'registration_blocked_email';

    public const REGISTRATION_WRONG_ANSWER = 'registration_wrong_answer';

    public const REGISTRATION_SPAM = 'registration_spam';

    public const TRAP_TRIGGERED = 'trap_triggered';

    public const FORM_TOO_FAST = 'form_too_fast';

    public const FORM_EXPIRED = 'form_expired';

    public const FORM_INVALID = 'form_invalid';

    /** Words for the screen. The keys are the only event names the screen will filter by. */
    public const LABELS = [
        self::LOGIN_LOCKOUT => 'Sign-in locked after too many failures',
        self::REGISTRATION_RATE_LIMITED => 'Registration refused: too many attempts from one address',
        self::REGISTRATION_PAUSED => 'Registration paused after a flood',
        self::REGISTRATION_BLOCKED_EMAIL => 'Registration refused: email address not accepted',
        self::REGISTRATION_WRONG_ANSWER => 'Registration refused: wrong answer to the question',
        self::REGISTRATION_SPAM => 'Registration dropped as spam',
        self::TRAP_TRIGGERED => 'Hidden field filled in',
        self::FORM_TOO_FAST => 'Form sent too quickly',
        self::FORM_EXPIRED => 'Form was open too long',
        self::FORM_INVALID => 'Form sent without its timing check',
    ];

    public const DEFAULT_RETENTION_DAYS = 30;

    public const DEFAULTS = ['security.log_retention_days' => self::DEFAULT_RETENTION_DAYS, 'security.log_purged_at' => 0];

    private const MAX_DETAIL_LENGTH = 200;

    /** Old rows are looked for at most this often, so a busy site does not pay for it on every refusal. */
    private const PURGE_INTERVAL_SECONDS = 3600;

    public function __construct(
        private readonly Database $database,
        private readonly Configuration $config,
    ) {
    }

    public function record(string $event, string $ipAddress, string $detail = ''): void
    {
        try {
            $this->insert($event, $ipAddress, $detail);
            $this->purgeIfDue();
        } catch (Throwable $exception) {
            error_log('[SecurityEventLog] ' . $exception::class . ': ' . $exception->getMessage());
        }
    }

    /**
     * For events a single attacker can cause thousands of times a minute: a repeat from the same address inside the
     * window is not written, so the log shows the burst began without being filled by it.
     */
    public function recordOnce(string $event, string $ipAddress, string $detail = '', int $withinSeconds = 600): void
    {
        try {
            $recent = $this->database->fetchColumn(
                'SELECT 1 FROM {prefix}security_events WHERE ip_address = :ip AND event = :event AND created_at >= :since LIMIT 1',
                ['ip' => $this->clean($ipAddress, 45), 'event' => $this->clean($event, 40), 'since' => date('Y-m-d H:i:s', time() - $withinSeconds)],
            );

            if ($recent === null || $recent === false) {
                $this->record($event, $ipAddress, $detail);
            }
        } catch (Throwable $exception) {
            error_log('[SecurityEventLog] ' . $exception::class . ': ' . $exception->getMessage());
        }
    }

    /**
     * Newest first. An event name that is not one of the known ones means no filter.
     *
     * @return list<array{event: string, label: string, ip: string, detail: string, at: string}>
     */
    public function recent(int $limit = 50, int $offset = 0, string $event = ''): array
    {
        $where = isset(self::LABELS[$event]) ? 'WHERE event = :event' : '';
        $params = $where === '' ? [] : ['event' => $event];

        $rows = $this->database->fetchAll(
            'SELECT event, ip_address, detail, created_at FROM {prefix}security_events ' . $where
            . ' ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
            $params,
        );

        return array_map(static fn (array $row): array => [
            'event' => (string) $row['event'],
            'label' => self::LABELS[(string) $row['event']] ?? (string) $row['event'],
            'ip' => (string) $row['ip_address'],
            'detail' => (string) $row['detail'],
            'at' => (string) $row['created_at'],
        ], $rows);
    }

    public function count(string $event = ''): int
    {
        $where = isset(self::LABELS[$event]) ? 'WHERE event = :event' : '';

        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}security_events ' . $where, $where === '' ? [] : ['event' => $event]);
    }

    /**
     * How long events are kept, in days, from 1 to 365.
     */
    public function retentionDays(): int
    {
        $days = $this->config->setting('security.log_retention_days', self::DEFAULT_RETENTION_DAYS);

        return is_int($days) ? max(1, min(365, $days)) : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * @return int the number of events removed
     */
    public function purgeExpired(): int
    {
        $this->config->setSetting('security.log_purged_at', time());

        return $this->database->execute(
            'DELETE FROM {prefix}security_events WHERE created_at < :cutoff',
            ['cutoff' => date('Y-m-d H:i:s', time() - $this->retentionDays() * 86400)],
        );
    }

    private function insert(string $event, string $ipAddress, string $detail): void
    {
        $this->database->execute(
            'INSERT INTO {prefix}security_events (event, ip_address, detail, created_at) VALUES (:event, :ip, :detail, :created)',
            [
                'event' => $this->clean($event, 40),
                'ip' => $this->clean($ipAddress, 45),
                'detail' => $this->clean($detail, self::MAX_DETAIL_LENGTH),
                'created' => date('Y-m-d H:i:s'),
            ],
        );
    }

    private function purgeIfDue(): void
    {
        $last = $this->config->setting('security.log_purged_at', 0);

        if (!is_int($last) || time() - $last >= self::PURGE_INTERVAL_SECONDS) {
            $this->purgeExpired();
        }
    }

    private function clean(string $value, int $maxLength): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value)), 0, $maxLength);
    }
}
