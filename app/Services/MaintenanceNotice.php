<?php

/**
 * Works out what the admin banner says while visitors are being turned away, and why.
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

use DateTimeImmutable;
use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Http\MaintenanceFlag;
use LumoraFiction\Core\Update\UpdateRun;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\User;

/**
 * Two different things take the public site offline and need different wording: maintenance mode, which an
 * administrator switched on (or scheduled) and can switch off, and the short-lived flag an update or restore raises
 * while it runs, which must never be presented as something to turn off. It is a state, not a problem, so it is not a
 * site alert and does not touch the alert count or the alert email.
 */
final class MaintenanceNotice
{
    public function __construct(
        private readonly MaintenanceMode $mode,
        private readonly string $root,
    ) {
    }

    /**
     * @return list<array{cause: string, message: string, link_label: string, url: string}> empty when nothing is offline
     *     or the person may not manage the site
     */
    public function forUser(User $user, ?DateTimeImmutable $now = null): array
    {
        if (!$user->can(Capability::ManageSite)) {
            return [];
        }

        $notices = [];
        $flag = MaintenanceFlag::read($this->root);

        if ($flag !== null) {
            $what = $flag['reason'] === UpdateRun::KIND_RESTORE ? 'a restore' : 'an update';
            $notices[] = [
                'cause' => 'update',
                'message' => sprintf('The site is offline while %s is running. Visitors see a maintenance notice until it finishes, and it comes back by itself.', $what),
                'link_label' => 'Open the progress screen',
                'url' => AdminUrl::to('maintenance/updates/run'),
            ];
        }

        $now ??= new DateTimeImmutable('now', $this->mode->zone());

        if ($this->mode->isActive($now)) {
            $notices[] = [
                'cause' => $this->mode->current()['enabled'] ? 'manual' : 'scheduled',
                'message' => $this->modeMessage($now),
                'link_label' => 'Open Maintenance mode',
                'url' => AdminUrl::to('maintenance/mode'),
            ];
        }

        return $notices;
    }

    /**
     * The same state in a few words, for the dashboard's site-status area; null when visitors are not being turned away.
     */
    public function summary(User $user, ?DateTimeImmutable $now = null): ?string
    {
        $notices = $this->forUser($user, $now);

        return $notices === [] ? null : implode(' ', array_column($notices, 'message'));
    }

    private function modeMessage(DateTimeImmutable $now): string
    {
        $settings = $this->mode->current();
        $zone = $this->mode->zone()->getName();
        $ends = $settings['ends'] !== '' ? $settings['ends'] . ' (' . $zone . ')' : null;
        $manual = $settings['enabled'];

        $text = 'The public site is offline for visitors: maintenance mode is on';

        if ($manual) {
            $text .= $ends === null
                ? ' and was switched on by hand, so it stays on until you turn it off.'
                : ' and was switched on by hand; it ends by itself at ' . $ends . '.';
        } else {
            $text .= ' because of a schedule' . ($ends === null ? '.' : ' that ends at ' . $ends . '.');
        }

        return $text . ' Administrators still see the site normally.';
    }
}
