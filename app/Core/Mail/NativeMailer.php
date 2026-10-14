<?php

/**
 * Sends plain-text email with PHP's built-in mail().
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

/**
 * mail() is the one transport most shared hosts guarantee is configured, and it needs no library.
 */
final class NativeMailer implements Mailer
{
    public function __construct(private readonly string $fromAddress)
    {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        // A line break in an address or subject would let it inject extra headers. Both are expected
        // to be well formed already; this is a second layer, and a bad recipient is refused outright.
        if (preg_match('/[\r\n]/', $to . $subject . $this->fromAddress) === 1 || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        // Encoded so a non-ASCII subject is not mangled in transit.
        $encodedSubject = preg_match('/[^\x20-\x7e]/', $subject) === 1 ? '=?UTF-8?B?' . base64_encode($subject) . '?=' : $subject;

        $headers = "From: {$this->fromAddress}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";

        // mail() raises a warning as well as returning false when no sendmail binary is configured,
        // and warnings become exceptions in this application.
        set_error_handler(static fn (): bool => true);

        try {
            return mail($to, $encodedSubject, $body, $headers);
        } finally {
            restore_error_handler();
        }
    }
}
