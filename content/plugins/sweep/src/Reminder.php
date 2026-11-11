<?php

/**
 * Puts one reminder in each Administrator's notifications inbox when cleanup candidates have built up.
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
use LumoraFiction\Services\NotificationService;
use Throwable;

/**
 * The count is taken at most once a week, however often the admin is opened, and an Administrator who has not yet read
 * the last reminder is not sent another. Nothing here can raise an error on the page that triggered it.
 */
final class Reminder
{
    public const KIND = 'sweep';

    private const INTERVAL = 604800;

    public function __construct(
        private readonly Configuration $config,
        private readonly Database $database,
        private readonly NotificationService $notifications,
        private readonly Sweeper $sweeper,
        private readonly SweepSettings $settings,
    ) {
    }

    /**
     * @return bool whether a reminder was sent
     */
    public function checkIfDue(?int $now = null): bool
    {
        $now ??= time();
        $threshold = $this->settings->reminderThreshold();

        try {
            $last = $this->config->setting('sweep.last_check', 0);

            if ($threshold < 1 || (is_int($last) && $now - $last < self::INTERVAL)) {
                return false;
            }

            // Written first, so a slow count that fails is not repeated on every page.
            $this->config->setSetting('sweep.last_check', $now);
            $total = $this->sweeper->total();

            if ($total < $threshold) {
                return false;
            }

            $sent = false;

            foreach ($this->database->fetchAll("SELECT id FROM {prefix}users WHERE role = 'administrator' AND status = 'active'") as $row) {
                $unread = (int) $this->database->fetchColumn(
                    'SELECT COUNT(*) FROM {prefix}notifications WHERE user_id = :user AND kind = :kind AND read_at IS NULL',
                    ['user' => (int) $row['id'], 'kind' => self::KIND],
                );

                if ($unread === 0) {
                    $this->notifications->notify((int) $row['id'], self::KIND, null, number_format($total) . ' items can be cleaned up with Sweep.', Screen::PATH);
                    $sent = true;
                }
            }

            return $sent;
        } catch (Throwable) {
            return false;
        }
    }
}
