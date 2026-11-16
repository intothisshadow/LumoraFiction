<?php

/**
 * What a reader of story files promises: HTML in, a work out.
 *
 * @package LumoraFiction
 * @subpackage Import
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Import;

/**
 * A reader only turns text into a work; it never fetches, runs or writes anything. Whatever it cannot know, it leaves
 * empty, so the preview asks the author instead of guessing.
 */
interface WorkParser
{
    /**
     * @throws ImportException when the file holds no work or no text
     */
    public function parse(string $html, string $fileName = ''): OtwWork;
}
