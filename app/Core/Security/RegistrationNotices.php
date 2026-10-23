<?php

/**
 * Raises an alert while registration is paused.
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

use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Models\AlertSeverity;
use LumoraFiction\Models\SiteAlert;

final class RegistrationNotices
{
    public function __construct(private readonly RegistrationThrottle $throttle)
    {
    }

    public function register(HookManager $hooks): void
    {
        $hooks->addFilter('admin_alerts', $this->add(...), 30);
    }

    /**
     * @param mixed $alerts the alerts collected so far
     * @return list<mixed>
     */
    public function add(mixed $alerts): array
    {
        $alerts = is_array($alerts) ? array_values($alerts) : [];
        $paused = $this->throttle->secondsPaused();

        if ($paused > 0) {
            $alerts[] = new SiteAlert(
                'registration.paused',
                AlertSeverity::Warning,
                sprintf('Registration is paused for about %d more minute(s) because a flood of sign-ups arrived. Open Settings > Spam protection to look at what happened or to open registration again.', max(1, (int) ceil($paused / 60))),
                '',
                'settings/spam',
            );
        }

        return $alerts;
    }
}
