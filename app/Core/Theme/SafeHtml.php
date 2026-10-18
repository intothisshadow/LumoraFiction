<?php

/**
 * Markup that has already been sanitised, so a template tag prints it as it is instead of escaping it.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Core\Theme;

/**
 * Only code that has run text through the content pipeline wraps a value in this. A plain string in the
 * view data is always escaped, so a controller that forgets to wrap something fails safe.
 */
final readonly class SafeHtml
{
    public function __construct(public string $html)
    {
    }
}
