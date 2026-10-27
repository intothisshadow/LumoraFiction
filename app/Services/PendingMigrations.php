<?php

/**
 * Applies database changes that came with newly uploaded files, the first time an Administrator opens the admin.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Database\Migrator;
use LumoraFiction\Models\AlertSeverity;
use LumoraFiction\Models\User;
use Throwable;

/**
 * An update made from the Updates screen applies its own database changes. Files uploaded by hand (the only way to reach
 * the first release that has an Updates screen) do not, so without this the new code would meet an old database. Only
 * an Administrator's request starts it, so a visitor can never trigger schema work, and the migrator's own lock stops
 * two requests applying the same change at once. A failure never stops the admin opening: it is shown as an alert.
 */
final class PendingMigrations
{
    public const ALERT_KEY = 'database.migration_failed';

    public function __construct(
        private readonly Migrator $migrator,
        private readonly AuditLogService $audit,
        private readonly ?SiteAlerts $alerts = null,
    ) {
    }

    /**
     * @return list<string> the changes that were applied, empty when none were waiting or they could not be applied
     */
    public function applyFor(User $actor, string $ipAddress): array
    {
        try {
            if ($this->migrator->pending() === []) {
                return [];
            }

            $applied = array_values($this->migrator->migrate());
        } catch (Throwable $exception) {
            // The cause, with any SQL, goes to the error log; the alert says only what to do about it.
            error_log('[PendingMigrations] ' . $exception::class . ': ' . $exception->getMessage() . ($exception->getPrevious() === null ? '' : ' (' . $exception->getPrevious()->getMessage() . ')'));
            $this->alerts?->raise(
                self::ALERT_KEY,
                AlertSeverity::Critical,
                'The database could not be brought up to date for this version, so some features may not work. The error log has the details.',
                '',
                'maintenance/system-information',
            );

            return [];
        }

        if ($applied !== []) {
            $this->alerts?->clear(self::ALERT_KEY);
            $this->audit->record($actor->id, 'database.migrated', 'database', null, ['migrations' => $applied], $ipAddress);
        }

        return $applied;
    }
}
