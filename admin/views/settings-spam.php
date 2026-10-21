<?php

/**
 * Settings › Spam protection.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;

/** @var array<string, int|string|bool> $values */
/** @var array<string, string> $errors */
/** @var int $pausedFor */
/** @var string $csrfToken */
/** @var string $resumeToken */
/** @var string $action */
/** @var string $resumeAction */
/** @var string $eventsUrl */

// One whole-number box with its hint and error, so the six of them read the same.
$number = static function (string $name, string $label, string $hint) use ($values, $errors): string {
    $described = $name . '_hint' . (isset($errors[$name]) ? ' ' . $name . '_error' : '');

    return '<div class="lf-field' . (isset($errors[$name]) ? ' lf-field--error' : '') . '">'
        . '<label for="' . Html::e($name) . '">' . Html::e($label) . '</label>'
        . '<input type="text" inputmode="numeric" id="' . Html::e($name) . '" name="' . Html::e($name) . '" value="' . Html::e((string) $values[$name]) . '" maxlength="6" aria-describedby="' . Html::e($described) . '">'
        . '<p class="lf-hint" id="' . Html::e($name) . '_hint">' . Html::e($hint) . '</p>'
        . (isset($errors[$name]) ? '<p class="lf-error" id="' . Html::e($name) . '_error">' . Html::e($errors[$name]) . '</p>' : '')
        . '</div>';
};
?>
<?php if ($pausedFor > 0): ?>
    <div class="lf-notice lf-notice--warning" role="status">
        <p>Registration is paused for about <?= (int) max(1, (int) ceil($pausedFor / 60)) ?> more minute(s) because a flood of sign-ups arrived.</p>
        <form method="post" action="<?= Html::e($resumeAction) ?>">
            <input type="hidden" name="csrf_token" value="<?= Html::e($resumeToken) ?>">
            <button type="submit" class="lf-button lf-button--secondary">Open registration now</button>
        </form>
    </div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="lf-notice lf-notice--error" role="alert" id="lf-error-summary" tabindex="-1">
        <p>Please fix the following:</p>
        <ul>
            <?php foreach ($errors as $field => $message): ?>
                <li><?= $field === 'form' ? Html::e($message) : '<a href="#' . Html::e($field) . '">' . Html::e($message) . '</a>' ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<p class="lf-lead">These protections work quietly in front of the registration form and the other forms a visitor can use without signing in. What they blocked is listed under <a href="<?= Html::e($eventsUrl) ?>">Maintenance &rsaquo; Security events</a>. Setting a limit to 0 turns that limit off.</p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <fieldset class="lf-card">
        <legend>Hidden fields and timing</legend>
        <p class="lf-hint">Forms carry fields only a program would fill in, and a signed note of when the form was shown. A form that fills the hidden fields is dropped without a word. A form sent too quickly is sent back with a request to wait.</p>
        <?= $number('min_seconds', 'Shortest time to fill in a form, in seconds', 'Forms sent faster than this are refused. 0 turns the timing check off; the hidden fields still work.') ?>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Limits on registration</legend>
        <p class="lf-hint">Every time the registration form is sent counts, whether or not it was filled in correctly. These limits apply to each internet address. A site behind a shared address, such as a school or an office, shares one allowance.</p>
        <?= $number('gap_seconds', 'Shortest wait between two submissions, in seconds', 'Someone who sends the form again sooner is asked to wait.') ?>
        <?= $number('ip_limit', 'Most submissions from one address in an hour', 'Counted over the last hour.') ?>
        <?= $number('flood_limit', 'Submissions from everyone in an hour that pause registration', 'When this many arrive in an hour, from any addresses, registration pauses for the time below, and the dashboard says so.') ?>
        <?= $number('pause_minutes', 'How long registration pauses, in minutes', 'You can open registration again earlier from this page.') ?>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Email addresses</legend>

        <div class="lf-choice">
            <input type="checkbox" id="block_disposable" name="block_disposable" value="1"<?= $values['block_disposable'] === true ? ' checked' : '' ?> aria-describedby="block_disposable_hint">
            <label for="block_disposable">Refuse throw-away email services</label>
            <p class="lf-hint" id="block_disposable_hint">Addresses from services that hand out temporary inboxes, such as Mailinator, cannot be used to register.</p>
        </div>

        <div class="lf-field<?= isset($errors['domain_blocklist']) ? ' lf-field--error' : '' ?>">
            <label for="domain_blocklist">Domains that cannot be used</label>
            <textarea id="domain_blocklist" name="domain_blocklist" rows="6" aria-describedby="domain_blocklist_hint<?= isset($errors['domain_blocklist']) ? ' domain_blocklist_error' : '' ?>"><?= Html::e((string) $values['domain_blocklist']) ?></textarea>
            <p class="lf-hint" id="domain_blocklist_hint">One domain per line, such as <code>example.com</code>. A subdomain of a listed domain is refused too. Lines starting with # are ignored.</p>
            <?php if (isset($errors['domain_blocklist'])): ?><p class="lf-error" id="domain_blocklist_error"><?= Html::e($errors['domain_blocklist']) ?></p><?php endif; ?>
        </div>
    </fieldset>

    <fieldset class="lf-card">
        <legend>A question to answer</legend>
        <p class="lf-hint">Optional. A short question that a person finds easy and a program does not, such as the name of this site. Leave both boxes empty to ask nothing. Capital letters and extra spaces in the answer do not matter.</p>

        <div class="lf-field<?= isset($errors['question']) ? ' lf-field--error' : '' ?>">
            <label for="question">Question</label>
            <input type="text" id="question" name="question" value="<?= Html::e((string) $values['question']) ?>" maxlength="200"<?= isset($errors['question']) ? ' aria-describedby="question_error"' : '' ?>>
            <?php if (isset($errors['question'])): ?><p class="lf-error" id="question_error"><?= Html::e($errors['question']) ?></p><?php endif; ?>
        </div>

        <div class="lf-field<?= isset($errors['answer']) ? ' lf-field--error' : '' ?>">
            <label for="answer">Answer</label>
            <input type="text" id="answer" name="answer" value="<?= Html::e((string) $values['answer']) ?>" maxlength="100" autocomplete="off"<?= isset($errors['answer']) ? ' aria-describedby="answer_error"' : '' ?>>
            <?php if (isset($errors['answer'])): ?><p class="lf-error" id="answer_error"><?= Html::e($errors['answer']) ?></p><?php endif; ?>
        </div>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Akismet</legend>
        <p class="lf-hint">Optional, and off unless you turn it on. Akismet is an outside service that judges whether a sign-up looks like spam. When it is on, the new person's email address, display name, internet address and browser name are sent to Akismet. If Akismet cannot be reached, sign-ups go through as usual.</p>

        <div class="lf-choice">
            <input type="checkbox" id="akismet_enabled" name="akismet_enabled" value="1"<?= $values['akismet_enabled'] === true ? ' checked' : '' ?>>
            <label for="akismet_enabled">Check new sign-ups with Akismet</label>
        </div>

        <div class="lf-field<?= isset($errors['akismet_key']) ? ' lf-field--error' : '' ?>">
            <label for="akismet_key">Akismet key</label>
            <input type="text" id="akismet_key" name="akismet_key" value="<?= Html::e((string) $values['akismet_key']) ?>" maxlength="64" autocomplete="off" spellcheck="false"<?= isset($errors['akismet_key']) ? ' aria-describedby="akismet_key_error"' : '' ?>>
            <?php if (isset($errors['akismet_key'])): ?><p class="lf-error" id="akismet_key_error"><?= Html::e($errors['akismet_key']) ?></p><?php endif; ?>
        </div>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Security events</legend>
        <?= $number('retention_days', 'Keep the list for how many days', 'Older entries are removed automatically.') ?>
    </fieldset>

    <p><button type="submit" class="lf-button">Save settings</button></p>
</form>
