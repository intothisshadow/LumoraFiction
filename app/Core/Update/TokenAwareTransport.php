<?php

/**
 * A transport that can send a GitHub token with requests to GitHub's API, and nowhere else.
 *
 * @package LumoraFiction
 * @subpackage Update
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.3.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Update;

/**
 * Kept apart from HttpTransport so a stand-in for the network in a test needs no knowledge of tokens.
 */
interface TokenAwareTransport extends HttpTransport
{
    /**
     * A copy that adds the token to requests for api.github.com. The token is never sent to any other host.
     */
    public function withToken(string $token): static;
}
