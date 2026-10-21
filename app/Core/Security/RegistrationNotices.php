<?php

/**
 * Tells the administrator's dashboard that registration is paused.
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

final class RegistrationNotices
{
    public function __construct(private readonly RegistrationThrottle $throttle)
    {
    }

    public function register(HookManager $hooks): void
    {
        $hooks->addFilter('admin_dashboard_notices', $this->add(...), 30);
    }

    /**
     * @param mixed $notices the notices collected so far
     * @return list<array{variant: string, message: string}>
     */
    public function add(mixed $notices): array
    {
        $notices = is_array($notices) ? array_values($notices) : [];
        $paused = $this->throttle->secondsPaused();

        if ($paused > 0) {
            $notices[] = [
                'variant' => 'warning',
                'message' => sprintf('Registration is paused for about %d more minute(s) because a flood of sign-ups arrived. Open Settings > Spam protection to look at what happened or to open registration again.', max(1, (int) ceil($paused / 60))),
            ];
        }

        return $notices;
    }
}
