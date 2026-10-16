<?php

/**
 * Sending a story back to its author with the reason.
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
use LumoraFiction\Models\Story;

/** @var Story $story */
/** @var string $owner */
/** @var string $reason */
/** @var int $limit */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $backUrl */
/** @var string $previewUrl */
?>
<?= $errorSummary ?>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-card">
        <h2>Send &ldquo;<?= Html::e($story->title) ?>&rdquo; back?</h2>
        <p>By <?= Html::e($owner !== '' ? $owner : 'an unknown author') ?>. The story becomes a draft again and the author sees your reason on their own screens. <a href="<?= Html::e($previewUrl) ?>">Preview the story</a></p>

        <div class="lf-field<?= isset($errors['reason']) ? ' lf-field--error' : '' ?>">
            <label for="reason">Reason for the author</label>
            <textarea id="reason" name="reason" rows="6" maxlength="<?= (int) $limit ?>" required aria-describedby="reason_hint<?= isset($errors['reason']) ? ' reason_error' : '' ?>"><?= Html::e($reason) ?></textarea>
            <p class="lf-hint" id="reason_hint">Say what needs to change before the story can be approved. Up to <?= number_format($limit) ?> characters.</p>
            <?php if (isset($errors['reason'])): ?><p class="lf-error" id="reason_error"><?= Html::e($errors['reason']) ?></p><?php endif; ?>
        </div>

        <p>
            <button type="submit" class="lf-button">Send back to the author</button>
            <a href="<?= Html::e($backUrl) ?>">Cancel</a>
        </p>
    </div>
</form>
