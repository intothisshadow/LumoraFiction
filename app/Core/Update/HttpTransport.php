<?php

/**
 * The two kinds of outgoing request the updater makes, so tests can stand in for the network.
 *
 * @package LumoraFiction
 * @subpackage Update
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Update;

interface HttpTransport
{
    /**
     * @throws UpdateException when the request fails or the answer is larger than allowed
     */
    public function fetch(string $url, int $maxBytes): string;

    /**
     * Saves the answer to a file and returns its size.
     *
     * @throws UpdateException when the request fails or the answer is larger than allowed
     */
    public function download(string $url, string $destination, int $maxBytes): int;
}
