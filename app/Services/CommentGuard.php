<?php

/**
 * Runs the site's spam controls over a comment before it is stored: the form shield, then the optional Akismet check.
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

use LumoraFiction\Core\Http\Request;
use LumoraFiction\Models\User;

/**
 * A suspected comment is kept, marked as spam, rather than dropped, so a moderator can restore a real one. Only a form
 * that was sent too fast, too late or without its fields is bounced back to be sent again, because that can be a real
 * person; the words for it come from the same FormGuard every other public form uses.
 */
final class CommentGuard
{
    public const ALLOW = 'allow';

    public const RETRY = 'retry';

    public const SPAM = 'spam';

    public function __construct(
        private readonly FormGuard $forms,
        private readonly SpamSettings $settings,
        private readonly AkismetClient $akismet,
    ) {
    }

    /**
     * @return array{outcome: string, error: string}
     */
    public function inspect(Request $request, User $commenter, string $body, string $formId): array
    {
        $decision = $this->forms->inspect($request, $formId);

        if ($decision->outcome === GuardOutcome::Reject) {
            return ['outcome' => self::RETRY, 'error' => $decision->errors['form'] ?? 'Something went wrong with the form. Please reload the page and try again.'];
        }

        if (!$decision->allowed()) {
            // The trap field was filled: answered like a normal post, but kept as spam.
            return ['outcome' => self::SPAM, 'error' => ''];
        }

        $current = $this->settings->current();

        if ($current['akismet_enabled'] && $current['akismet_key'] !== '') {
            $agent = $request->server['HTTP_USER_AGENT'] ?? '';

            // A null answer means Akismet could not be asked, which must never stop a real person commenting.
            if ($this->akismet->isSpamComment($current['akismet_key'], $body, $commenter->displayName, $request->ip(), is_string($agent) ? $agent : '') === true) {
                return ['outcome' => self::SPAM, 'error' => ''];
            }
        }

        return ['outcome' => self::ALLOW, 'error' => ''];
    }
}
