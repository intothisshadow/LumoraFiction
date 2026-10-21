<?php

/**
 * Hidden trap fields and the form-timing check for public forms.
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

/**
 * Field names are derived from the site's secret key, so a bot cannot carry one list of names that works against every
 * Lumora Fiction site, and they do not look like fields browsers and password managers fill in. The names stay the same
 * between requests, so a page that was cached for a moment still posts names that are recognised.
 *
 * The timing stamp is signed with the same key and tied to one form, so a stamp from one form cannot be reused on
 * another and a bot cannot invent an old one. The signature only proves when the form was shown; the CSRF token is
 * still what proves the request belongs to a visitor.
 */
final class FormShield
{
    public const OK = 'ok';

    /** A trap field was filled in. */
    public const TRAPPED = 'trapped';

    public const TOO_FAST = 'too_fast';

    public const EXPIRED = 'expired';

    /** The timing fields are missing or do not match, as when a bot posts without ever loading the form. */
    public const INVALID = 'invalid';

    /** A form left open for a day is more likely to be a stale page than a person. */
    public const MAX_AGE_SECONDS = 86400;

    public function __construct(
        private readonly string $secretKey,
        private readonly int $minimumSeconds = 3,
    ) {
    }

    /**
     * @return list<string> the names of the two fields a person never fills in
     */
    public function trapNames(): array
    {
        return [$this->derive('trap-a'), $this->derive('trap-b')];
    }

    /**
     * @return array{time: string, mac: string} the names of the two hidden timing fields
     */
    public function timingNames(): array
    {
        return ['time' => $this->derive('time'), 'mac' => $this->derive('mac')];
    }

    public function timingEnabled(): bool
    {
        return $this->minimumSeconds > 0;
    }

    /**
     * @return array{time: string, mac: string} the values to print in the two timing fields
     */
    public function stamp(string $formId, ?int $now = null): array
    {
        $time = (string) ($now ?? time());

        return ['time' => $time, 'mac' => $this->sign($formId, $time)];
    }

    /**
     * @param array<string, mixed> $post
     */
    public function inspect(array $post, string $formId, ?int $now = null): string
    {
        foreach ($this->trapNames() as $name) {
            $value = $post[$name] ?? '';

            if (is_array($value) || trim((string) $value) !== '') {
                return self::TRAPPED;
            }
        }

        if (!$this->timingEnabled()) {
            return self::OK;
        }

        $names = $this->timingNames();
        $time = $post[$names['time']] ?? null;
        $mac = $post[$names['mac']] ?? null;

        if (!is_string($time) || !is_string($mac) || preg_match('/^[0-9]{1,12}\z/', $time) !== 1 || !hash_equals($this->sign($formId, $time), $mac)) {
            return self::INVALID;
        }

        $age = ($now ?? time()) - (int) $time;

        if ($age < 0) {
            return self::INVALID;
        }

        if ($age < $this->minimumSeconds) {
            return self::TOO_FAST;
        }

        return $age > self::MAX_AGE_SECONDS ? self::EXPIRED : self::OK;
    }

    private function sign(string $formId, string $time): string
    {
        return hash_hmac('sha256', $formId . '|' . $time, $this->secretKey);
    }

    private function derive(string $slot): string
    {
        return 'lf_' . substr(hash_hmac('sha256', 'form-shield-' . $slot, $this->secretKey), 0, 10);
    }
}
