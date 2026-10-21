<?php

/**
 * The email-domain block lists and the challenge question, as pure checks.
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
 * Every rule arrives through the constructor, so each can be tested alone. The reason a sign-up was refused is for
 * the security log; the visitor is only told the address cannot be used, never which list it was on.
 */
final class SignupRules
{
    public const REASON_BLOCKED_DOMAIN = 'blocked_domain';

    public const REASON_DISPOSABLE_DOMAIN = 'disposable_domain';

    /**
     * Services that exist to hand out throw-away inboxes. A reader has no need to confirm an account at one, and a
     * bot that wants to pass the confirmation step uses these.
     *
     * @var list<string>
     */
    public const DISPOSABLE_DOMAINS = [
        '10minutemail.com', '10minutemail.net', 'anonbox.net', 'burnermail.io', 'discard.email',
        'dispostable.com', 'emailondeck.com', 'fakeinbox.com', 'getnada.com', 'grr.la',
        'guerrillamail.biz', 'guerrillamail.com', 'guerrillamail.de', 'guerrillamail.net',
        'guerrillamail.org', 'guerrillamailblock.com', 'inboxkitten.com', 'maildrop.cc',
        'mailcatch.com', 'mailforspam.com', 'mailinator.com', 'mailnesia.com', 'mintemail.com',
        'moakt.com', 'mohmal.com', 'mytemp.email', 'sharklasers.com', 'spambox.us',
        'spamgourmet.com', 'temp-mail.org', 'tempail.com', 'tempinbox.com', 'tempmail.com',
        'tempmailo.com', 'throwawaymail.com', 'trashmail.com', 'trashmail.net', 'yopmail.com',
        'yopmail.fr', 'yopmail.net',
    ];

    /**
     * @param list<string> $blockedDomains
     */
    public function __construct(
        private readonly array $blockedDomains = [],
        private readonly bool $blockDisposable = true,
        private readonly string $question = '',
        private readonly string $expectedAnswer = '',
    ) {
    }

    /**
     * Reads a box of domains: one per line, commas and spaces also separate, `#` starts a comment, and a leading `@`
     * or `*.` is dropped. Anything that is not a plausible domain name is returned apart, so a typo is shown to the
     * administrator instead of being saved as a rule that never matches.
     *
     * @return array{domains: list<string>, invalid: list<string>}
     */
    public static function parseDomains(string $raw): array
    {
        $domains = [];
        $invalid = [];

        foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
            foreach (preg_split('/[\s,]+/', trim(explode('#', $line, 2)[0]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
                $domain = strtolower(ltrim($entry, '@'));
                $domain = str_starts_with($domain, '*.') ? substr($domain, 2) : $domain;

                if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+\z/', $domain) !== 1 || strlen($domain) > 190) {
                    $invalid[] = mb_substr($entry, 0, 60);
                } elseif (!in_array($domain, $domains, true)) {
                    $domains[] = $domain;
                }
            }
        }

        return ['domains' => $domains, 'invalid' => $invalid];
    }

    /**
     * Why an address may not be used, or null when it may. Only the part after the last "@" counts.
     */
    public function emailProblem(string $email): ?string
    {
        $at = strrpos($email, '@');
        $domain = $at === false ? '' : strtolower(substr($email, $at + 1));

        if ($domain === '') {
            return null;
        }

        if ($this->matches($domain, $this->blockedDomains)) {
            return self::REASON_BLOCKED_DOMAIN;
        }

        return $this->blockDisposable && $this->matches($domain, self::DISPOSABLE_DOMAINS) ? self::REASON_DISPOSABLE_DOMAIN : null;
    }

    public function asksQuestion(): bool
    {
        return $this->question !== '' && $this->expectedAnswer !== '';
    }

    /**
     * Case, spacing and surrounding blanks do not matter, so "  Blue " is the same answer as "blue".
     */
    public function answerIsRight(string $answer): bool
    {
        if (!$this->asksQuestion()) {
            return true;
        }

        return hash_equals($this->normalise($this->expectedAnswer), $this->normalise($answer));
    }

    /**
     * A subdomain of a listed domain counts as that domain, so blocking example.com also blocks mail.example.com.
     *
     * @param list<string> $domains
     */
    private function matches(string $domain, array $domains): bool
    {
        foreach ($domains as $listed) {
            if ($domain === $listed || str_ends_with($domain, '.' . $listed)) {
                return true;
            }
        }

        return false;
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}
