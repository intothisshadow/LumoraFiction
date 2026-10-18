<?php

/**
 * Storybook theme: the colour scheme chooser in the header, shown only when the reader script has run.
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

?>
<div class="lf-field lf-js-only">
    <label class="lf-field__label" for="lf-pref-scheme">Colours</label>
    <select class="lf-select" id="lf-pref-scheme" data-lf-pref="scheme">
        <option value="system">Match my device</option>
        <option value="light">Light</option>
        <option value="dark">Dark</option>
    </select>
</div>
