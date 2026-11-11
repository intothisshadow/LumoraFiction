<?php

/**
 * Sends one small JSON document to an https address and reports the HTTP status.
 *
 * @package LumoraFiction
 * @subpackage Update
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Update;

/**
 * Kept apart from HttpTransport, which only reads: the updater needs a capped download and the install ping needs a
 * short POST, and a test double for one should not have to implement the other.
 */
interface HttpPoster
{
    /**
     * @return int the HTTP status code of the answer; the answer's body is never read
     * @throws UpdateException when the request cannot be made at all
     */
    public function postJson(string $url, string $json, int $timeout): int;
}
