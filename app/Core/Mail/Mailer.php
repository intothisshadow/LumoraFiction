<?php

/**
 * Outbound email, kept behind an interface so tests can substitute a fake.
 *
 * @package LumoraFiction
 * @subpackage Mail
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Mail;

interface Mailer
{
    /**
     * @return bool true if the transport accepted the message for delivery; this is not a delivery guarantee
     */
    public function send(string $to, string $subject, string $body): bool;
}
