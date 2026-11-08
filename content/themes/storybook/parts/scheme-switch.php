<?php

/**
 * Storybook theme: the colour button in the header: one press steps through Auto, Dark and Light. Shown only when the reader script has run.
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
<div class="lf-scheme-switch lf-js-only">
    <button type="button" class="lf-scheme-button" id="lf-pref-scheme" data-lf-pref="scheme" data-lf-mode="system" data-lf-cycle="system dark light" data-lf-name="Colours"
        data-lf-state-system="matching your device" data-lf-state-dark="dark" data-lf-state-light="light"
        data-lf-press-system="Press to match your device" data-lf-press-dark="Press for dark" data-lf-press-light="Press for light"
        data-lf-label-system="Auto" data-lf-label-dark="Dark" data-lf-label-light="Light"
        aria-label="Colours: matching your device. Press for dark">
        <svg class="lf-scheme-button__icon lf-scheme-button__icon--system" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
            <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/>
            <path d="M12 3a9 9 0 0 0 0 18z" fill="currentColor"/>
        </svg>
        <svg class="lf-scheme-button__icon lf-scheme-button__icon--dark" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
            <path d="M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5a8.5 8.5 0 1 0 11 11z" fill="currentColor"/>
        </svg>
        <svg class="lf-scheme-button__icon lf-scheme-button__icon--light" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
            <circle cx="12" cy="12" r="4" fill="currentColor"/>
            <path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9L7 7M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <span class="lf-scheme-button__label" data-lf-scheme-label>Auto</span>
    </button>
</div>
