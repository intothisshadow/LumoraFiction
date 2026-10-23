<?php

/**
 * Alerts about the site itself, for Administrators: checked live, kept from events, and offered to plugins.
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
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Mail\Mailer;
use LumoraFiction\Models\AlertSeverity;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\SiteAlert;
use LumoraFiction\Models\User;
use Throwable;

/**
 * Two kinds. A state that can be checked again (debug mode is on, a plugin is failing) is worked out each time through
 * the admin_alerts filter and clears itself when its cause goes. An event that happened (a burst of lockouts, mail
 * failing) is stored under a key, so a repeating problem is one row with a count, and can be dismissed. Raising never
 * throws: a problem while recording an alert must not break the request that noticed it. Messages come from code and
 * must never include anything a visitor typed.
 */
final class SiteAlerts
{
    public const DEFAULTS = ['alerts.email_critical' => false];

    public const RETENTION_DAYS = 30;

    public const MAX_ROWS = 200;

    /** A dismissed alert returns only if the problem comes back after this long without it. */
    public const QUIET_SECONDS = 604800;

    public const EMAIL_INTERVAL_SECONDS = 86400;

    private ?Request $request = null;

    /** @var list<SiteAlert>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly Database $database,
        private readonly Configuration $config,
        private readonly HookManager $hooks,
        private readonly AuditLogService $audit,
        private readonly ?Mailer $mailer = null,
        private readonly string $siteName = 'Lumora Fiction',
        private readonly string $siteUrl = '',
    ) {
    }

    /**
     * Remembers the request being answered, so the checks that need it (is this page on HTTPS) see the same one the
     * screen does. Also forgets what an earlier request worked out.
     */
    public function useRequest(Request $request): void
    {
        $this->request = $request;
        $this->cache = null;
    }

    /**
     * Raises, or repeats, a stored alert. A repeat adds one to its count and refreshes its message; a dismissed alert
     * stays dismissed unless it was quiet for QUIET_SECONDS before this repeat.
     *
     * @param string $path an admin address relative to the admin root, for the "fix it" link; empty for none
     */
    public function raise(string $key, AlertSeverity $severity, string $message, string $detail = '', string $path = ''): void
    {
        if (!SiteAlert::validKey($key) || !SiteAlert::validPath($path) || trim($message) === '') {
            error_log('[SiteAlerts] An alert with an invalid key, path or message was dropped.');

            return;
        }

        try {
            $now = date('Y-m-d H:i:s');
            $message = mb_substr(trim($message), 0, SiteAlert::MESSAGE_MAX);
            $detail = mb_substr(trim($detail), 0, SiteAlert::DETAIL_MAX);

            // One statement, so two requests raising the same key at once cannot make two rows. The dismissed column is
            // assigned before last_seen, because MySQL applies the assignments in order and the test needs the old value.
            $this->database->execute(
                'INSERT INTO {prefix}site_alerts (alert_key, severity, message, detail, path, occurrences, first_seen, last_seen)
                 VALUES (:key, :severity, :message, :detail, :path, 1, :first, :last)
                 ON DUPLICATE KEY UPDATE
                    dismissed_at = IF(dismissed_at IS NOT NULL AND last_seen < :cutoff, NULL, dismissed_at),
                    occurrences = occurrences + 1,
                    severity = :severity_new,
                    message = :message_new,
                    detail = :detail_new,
                    path = :path_new,
                    last_seen = :last_new',
                [
                    'key' => $key,
                    'severity' => $severity->value,
                    'message' => $message,
                    'detail' => $detail,
                    'path' => $path,
                    'first' => $now,
                    'last' => $now,
                    'cutoff' => date('Y-m-d H:i:s', time() - self::QUIET_SECONDS),
                    'severity_new' => $severity->value,
                    'message_new' => $message,
                    'detail_new' => $detail,
                    'path_new' => $path,
                    'last_new' => $now,
                ],
            );

            $this->cache = null;
            $this->prune();

            if ($severity === AlertSeverity::Critical) {
                $this->emailOnce($key, $message);
            }
        } catch (Throwable $exception) {
            error_log('[SiteAlerts] ' . $exception::class . ': ' . $exception->getMessage());
        }
    }

    /**
     * Removes a stored alert because its cause is gone. Does nothing for a key that is not stored.
     */
    public function clear(string $key): void
    {
        try {
            $this->database->execute('DELETE FROM {prefix}site_alerts WHERE alert_key = :key', ['key' => $key]);
            $this->cache = null;
        } catch (Throwable $exception) {
            error_log('[SiteAlerts] ' . $exception::class . ': ' . $exception->getMessage());
        }
    }

    /**
     * Hides a stored alert until its problem returns after a quiet period. Live alerts have no row, so they cannot be
     * dismissed; fixing the cause is what clears them.
     */
    public function dismiss(User $actor, int $id, string $ipAddress = ''): bool
    {
        if (!$actor->can(Capability::ManageSite)) {
            return false;
        }

        $row = $this->database->fetchOne('SELECT alert_key FROM {prefix}site_alerts WHERE id = :id AND dismissed_at IS NULL', ['id' => $id]);

        if ($row === null) {
            return false;
        }

        $this->database->execute('UPDATE {prefix}site_alerts SET dismissed_at = :now WHERE id = :id', ['now' => date('Y-m-d H:i:s'), 'id' => $id]);
        $this->cache = null;
        $this->audit->record($actor->id, 'alert.dismiss', 'alert', (string) $id, ['key' => (string) $row['alert_key']], $ipAddress);

        return true;
    }

    /**
     * Every alert needing a look, most serious first: the ones checked live (core's, plugins', and notices from the
     * older dashboard filter) and the stored ones that have not been dismissed. Empty for anyone but an administrator.
     *
     * @return list<SiteAlert>
     */
    public function all(User $viewer): array
    {
        if (!$viewer->can(Capability::ManageSite)) {
            return [];
        }

        if ($this->cache !== null) {
            return $this->cache;
        }

        $alerts = [];

        foreach ($this->live() as $alert) {
            $alerts[$alert->key] = $alert;
        }

        foreach ($this->stored() as $alert) {
            $alerts[$alert->key] ??= $alert;
        }

        $alerts = array_values($alerts);
        // Stable: alerts of one severity keep the order they were collected in.
        usort($alerts, static fn (SiteAlert $a, SiteAlert $b): int => $a->severity->rank() <=> $b->severity->rank());

        return $this->cache = $alerts;
    }

    /**
     * The number the header shows: critical alerts and warnings, not plain notices.
     */
    public function badgeCount(User $viewer): int
    {
        return count(array_filter($this->all($viewer), static fn (SiteAlert $alert): bool => $alert->severity->needsAttention()));
    }

    /**
     * @return list<SiteAlert> the most serious few, for the dashboard
     */
    public function top(User $viewer, int $limit = 5): array
    {
        return array_slice($this->all($viewer), 0, max(1, $limit));
    }

    public function emailsCritical(): bool
    {
        return $this->config->setting('alerts.email_critical', false) === true;
    }

    public function setEmailCritical(User $actor, bool $on, string $ipAddress = ''): bool
    {
        if (!$actor->can(Capability::ManageSite)) {
            return false;
        }

        if ($this->emailsCritical() !== $on) {
            $this->config->setSetting('alerts.email_critical', $on);
            $this->audit->record($actor->id, 'alert.email_setting', 'setting', 'alerts.email_critical', ['to' => $on], $ipAddress);
        }

        return true;
    }

    /**
     * @return list<SiteAlert>
     */
    private function live(): array
    {
        $request = $this->request ?? Request::fromGlobals('GET', '/');
        $alerts = [];

        $collected = $this->hooks->applyFilters('admin_alerts', [], $request);

        foreach (is_array($collected) ? $collected : [] as $item) {
            $alert = $item instanceof SiteAlert ? $item : SiteAlert::fromArray($item);

            if ($alert !== null) {
                $alerts[] = $alert;
            }
        }

        // Plugins written before alerts existed still add to the dashboard's list; those notices are shown here too.
        $legacy = $this->hooks->applyFilters('admin_dashboard_notices', [], $request);

        foreach (is_array($legacy) ? $legacy : [] as $notice) {
            if (!is_array($notice) || !is_string($notice['message'] ?? null) || trim($notice['message']) === '') {
                continue;
            }

            $severity = match ($notice['variant'] ?? null) {
                'error' => AlertSeverity::Critical,
                'warning' => AlertSeverity::Warning,
                'info', 'success' => AlertSeverity::Info,
                default => null,
            };

            if ($severity !== null && mb_strlen($notice['message']) <= SiteAlert::MESSAGE_MAX) {
                $alerts[] = new SiteAlert('notice.' . substr(sha1($notice['message']), 0, 16), $severity, trim($notice['message']));
            }
        }

        return $alerts;
    }

    /**
     * @return list<SiteAlert>
     */
    private function stored(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM {prefix}site_alerts WHERE dismissed_at IS NULL ORDER BY last_seen DESC, id DESC LIMIT ' . self::MAX_ROWS,
        );
        $alerts = [];

        foreach ($rows as $row) {
            $severity = AlertSeverity::tryFrom((string) $row['severity']);

            if ($severity === null) {
                continue;
            }

            $alerts[] = new SiteAlert(
                (string) $row['alert_key'],
                $severity,
                (string) $row['message'],
                (string) $row['detail'],
                (string) $row['path'],
                (int) $row['occurrences'],
                new DateTimeImmutable((string) $row['first_seen']),
                new DateTimeImmutable((string) $row['last_seen']),
                (int) $row['id'],
            );
        }

        return $alerts;
    }

    /**
     * Removes alerts untouched for the retention period, then the oldest beyond the row cap.
     */
    private function prune(): void
    {
        $this->database->execute('DELETE FROM {prefix}site_alerts WHERE last_seen < :cutoff', ['cutoff' => date('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400)]);

        $excess = (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}site_alerts') - self::MAX_ROWS;

        if ($excess > 0) {
            // Ordered by age, never by anything a visitor controls. The limit is a computed integer.
            $this->database->execute('DELETE FROM {prefix}site_alerts ORDER BY last_seen ASC, id ASC LIMIT ' . $excess);
        }
    }

    /**
     * Tells the site owner about a critical alert, if they asked to be told, at most once per alert per day. The claim is
     * one conditional update, so two requests raising it together send one message.
     */
    private function emailOnce(string $key, string $message): void
    {
        if ($this->mailer === null || !$this->emailsCritical() || $this->siteUrl === '') {
            return;
        }

        $claimed = $this->database->execute(
            'UPDATE {prefix}site_alerts SET emailed_at = :now WHERE alert_key = :key AND (emailed_at IS NULL OR emailed_at < :cutoff)',
            ['now' => date('Y-m-d H:i:s'), 'key' => $key, 'cutoff' => date('Y-m-d H:i:s', time() - self::EMAIL_INTERVAL_SECONDS)],
        );

        if ($claimed < 1) {
            return;
        }

        // The owner is the oldest active administrator account.
        $owner = $this->database->fetchOne("SELECT email FROM {prefix}users WHERE role = 'administrator' AND status = 'active' ORDER BY id ASC LIMIT 1");

        if ($owner === null) {
            return;
        }

        $sent = $this->mailer->send(
            (string) $owner['email'],
            'A critical alert on ' . $this->siteName,
            "Something on {$this->siteName} needs your attention:\n\n{$message}\n\nSee all alerts: {$this->siteUrl}/admin/maintenance/alerts\n\n"
            . "You are getting this because \"Email me about critical alerts\" is on in Maintenance > Alerts. It sends at most one message per alert per day.\n",
        );

        if (!$sent) {
            error_log('[SiteAlerts] The critical alert email could not be handed to the mail transport.');
        }
    }
}
