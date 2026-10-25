<?php

/**
 * Admin view: taking the public site offline for visitors, now or on a schedule.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;

/** @var array{enabled: bool, message: string, allowlist: string, starts: string, ends: string} $values */
/** @var array<string, string> $errors */
/** @var bool $active */
/** @var string $yourAddress */
/** @var string $zone */
/** @var string $defaultMessage */
/** @var int $maxMessage */
/** @var string $csrfToken */
/** @var string $action */
?>
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

<p class="lf-lead">Maintenance mode shows visitors a short notice instead of the site, with a status that tells search engines to come back later. The admin area stays open, and so does the whole site for administrators who are signed in and for the addresses you list. An update turns the site off for visitors by itself while it runs.</p>

<?php if ($active): ?>
    <p class="lf-notice lf-notice--warning" role="status"><strong>Visitors are being turned away right now.</strong></p>
<?php endif; ?>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <fieldset class="lf-card">
        <legend>Switch</legend>

        <div class="lf-choice">
            <input type="checkbox" id="enabled" name="enabled" value="1"<?= $values['enabled'] ? ' checked' : '' ?>>
            <label for="enabled">Take the site offline for visitors now</label>
        </div>

        <div class="lf-field<?= isset($errors['message']) ? ' lf-field--error' : '' ?>">
            <label for="message">Message for visitors</label>
            <textarea id="message" name="message" rows="3" maxlength="<?= (int) $maxMessage ?>" aria-describedby="message_hint<?= isset($errors['message']) ? ' message_error' : '' ?>"><?= Html::e($values['message']) ?></textarea>
            <p class="lf-hint" id="message_hint">Plain text, up to <?= (int) $maxMessage ?> characters. Left empty, visitors read: &ldquo;<?= Html::e($defaultMessage) ?>&rdquo;</p>
            <?php if (isset($errors['message'])): ?><p class="lf-error" id="message_error"><?= Html::e($errors['message']) ?></p><?php endif; ?>
        </div>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Schedule</legend>
        <p class="lf-hint">Optional. The site goes offline at the start and comes back at the end by itself, with nobody needing to be there. Times are in the server&rsquo;s time zone (<?= Html::e($zone) ?>). With the switch above on, the end time also says when it comes back.</p>

        <div class="lf-field<?= isset($errors['starts']) ? ' lf-field--error' : '' ?>">
            <label for="starts">Offline from</label>
            <input type="text" id="starts" name="starts" value="<?= Html::e($values['starts']) ?>" placeholder="2026-11-01 02:00" maxlength="16" autocomplete="off" aria-describedby="starts_hint<?= isset($errors['starts']) ? ' starts_error' : '' ?>">
            <p class="lf-hint" id="starts_hint">Year-month-day and hour:minute.</p>
            <?php if (isset($errors['starts'])): ?><p class="lf-error" id="starts_error"><?= Html::e($errors['starts']) ?></p><?php endif; ?>
        </div>

        <div class="lf-field<?= isset($errors['ends']) ? ' lf-field--error' : '' ?>">
            <label for="ends">Back online at</label>
            <input type="text" id="ends" name="ends" value="<?= Html::e($values['ends']) ?>" placeholder="2026-11-01 04:00" maxlength="16" autocomplete="off"<?= isset($errors['ends']) ? ' aria-describedby="ends_error"' : '' ?>>
            <?php if (isset($errors['ends'])): ?><p class="lf-error" id="ends_error"><?= Html::e($errors['ends']) ?></p><?php endif; ?>
        </div>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Addresses that still see the site</legend>

        <div class="lf-field<?= isset($errors['allowlist']) ? ' lf-field--error' : '' ?>">
            <label for="allowlist">Allowed addresses</label>
            <textarea id="allowlist" name="allowlist" rows="4" aria-describedby="allowlist_hint<?= isset($errors['allowlist']) ? ' allowlist_error' : '' ?>"><?= Html::e($values['allowlist']) ?></textarea>
            <p class="lf-hint" id="allowlist_hint">One per line: an address such as 203.0.113.5, or a range such as 203.0.113.0/24. Useful for a tester or an editor who is not signed in. You are connecting from <?= Html::e($yourAddress) ?>.</p>
            <?php if (isset($errors['allowlist'])): ?><p class="lf-error" id="allowlist_error"><?= Html::e($errors['allowlist']) ?></p><?php endif; ?>
        </div>
    </fieldset>

    <p><button type="submit" class="lf-button">Save settings</button></p>
</form>
