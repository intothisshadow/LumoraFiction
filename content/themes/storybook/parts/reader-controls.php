<?php

/**
 * Storybook theme: text size and reading width choosers for a chapter, shown only when the reader script has run.
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
<div class="lf-reader-controls lf-js-only" role="group" aria-label="Reading settings">
    <div class="lf-field">
        <label class="lf-field__label" for="lf-pref-size">Text size</label>
        <select class="lf-select" id="lf-pref-size" data-lf-pref="size">
            <option value="small">Small</option>
            <option value="default">Default</option>
            <option value="large">Large</option>
            <option value="xlarge">Extra large</option>
        </select>
    </div>
    <div class="lf-field">
        <label class="lf-field__label" for="lf-pref-width">Reading width</label>
        <select class="lf-select" id="lf-pref-width" data-lf-pref="width">
            <option value="narrow">Narrow</option>
            <option value="default">Default</option>
            <option value="wide">Wide</option>
        </select>
    </div>
</div>
