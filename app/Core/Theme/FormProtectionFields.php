<?php

/**
 * Prints the hidden trap and timing fields inside a protected public form.
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

use LumoraFiction\Core\Hooks\HookManager;
use LumoraFiction\Core\Security\FormShield;
use LumoraFiction\Helpers\Html;

/**
 * A theme only fires the lf_form_fields action once inside each form; the markup, the field names and the signing stay
 * in core, so a theme author cannot get them wrong and a new protection reaches every theme.
 */
final class FormProtectionFields
{
    /** The forms a visitor can use without being signed in, and that send mail or create something. */
    public const PROTECTED_FORMS = ['account_register', 'account_forgot', 'account_resend'];

    public function __construct(private readonly FormShield $shield)
    {
    }

    public function register(HookManager $hooks): void
    {
        $hooks->addAction('lf_form_fields', $this->print(...));
    }

    public function print(string $formId = ''): void
    {
        if (!in_array($formId, self::PROTECTED_FORMS, true)) {
            return;
        }

        [$first, $second] = $this->shield->trapNames();

        // aria-hidden and a negative tabindex keep screen readers, keyboard users and autofill out of the trap.
        echo '<div class="lf-form__trap" aria-hidden="true">',
            '<label for="lf-trap-a">Leave this field empty</label>',
            '<input type="text" id="lf-trap-a" name="', Html::e($first), '" value="" tabindex="-1" autocomplete="off">',
            '<label for="lf-trap-b">Do not fill in this field</label>',
            '<input type="text" id="lf-trap-b" name="', Html::e($second), '" value="" tabindex="-1" autocomplete="off">',
            '</div>', "\n";

        if ($this->shield->timingEnabled()) {
            $names = $this->shield->timingNames();
            $stamp = $this->shield->stamp($formId);

            echo '<input type="hidden" name="', Html::e($names['time']), '" value="', Html::e($stamp['time']), '">',
                '<input type="hidden" name="', Html::e($names['mac']), '" value="', Html::e($stamp['mac']), '">', "\n";
        }
    }
}
