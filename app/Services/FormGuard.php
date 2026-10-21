<?php

/**
 * The trap-field and timing check for public forms, with what it does about a failure.
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

use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Security\FormShield;

/**
 * A filled trap field is answered silently, because a person cannot see it and telling the sender it was caught
 * would let a bot learn which field to leave alone. A form sent too fast, too late or without its timing fields can be a
 * real visitor (a password manager, a stale page, a theme that does not print the fields), so those are told what to do.
 */
final class FormGuard
{
    public function __construct(
        private readonly FormShield $shield,
        private readonly SecurityEventLog $events,
    ) {
    }

    public function inspect(Request $request, string $formId): GuardDecision
    {
        $ip = $request->ip();

        switch ($this->shield->inspect($request->post, $formId)) {
            case FormShield::OK:
                return GuardDecision::allow();

            case FormShield::TRAPPED:
                $this->events->recordOnce(SecurityEventLog::TRAP_TRIGGERED, $ip, $formId);

                return GuardDecision::silent();

            case FormShield::TOO_FAST:
                $this->events->recordOnce(SecurityEventLog::FORM_TOO_FAST, $ip, $formId);

                return GuardDecision::reject(['form' => 'That was very quick. Please wait a few seconds, then send the form again.']);

            case FormShield::EXPIRED:
                $this->events->recordOnce(SecurityEventLog::FORM_EXPIRED, $ip, $formId);

                return GuardDecision::reject(['form' => 'This form was open for a long time. Please check what you entered and send it again.']);

            default:
                $this->events->recordOnce(SecurityEventLog::FORM_INVALID, $ip, $formId);

                return GuardDecision::reject(['form' => 'Something went wrong with the form. Please reload the page and try again.']);
        }
    }
}
