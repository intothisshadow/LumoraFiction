<?php

/**
 * What the sign-up and form protections decided.
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

enum GuardOutcome
{
    /** Nothing looked wrong; carry on. */
    case Allow;

    /** A mistake a real visitor can put right, so the form is shown again with a message. */
    case Reject;

    /** Looks automated: do nothing, but answer as if it worked so the sender learns nothing. */
    case Silent;

    /** This address has tried too often; it must wait. */
    case Limited;

    /** The whole site is refusing sign-ups for a while. */
    case Paused;
}
