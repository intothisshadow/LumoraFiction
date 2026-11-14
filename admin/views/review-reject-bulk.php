<?php

/**
 * Asks for the one reason that goes to the authors of several stories being sent back.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\Story;

/** @var list<Story> $stories only stories that are waiting */
/** @var string $reason */
/** @var int $limit */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var string $csrfToken */
/** @var string $action */
/** @var array<string, string> $kept the sort and page to return to */
/** @var string $backUrl */
?>
<?= $errorSummary ?>

<?php if ($stories === []): ?>
    <p>None of the selected stories is waiting for approval.</p>
    <p><a href="<?= Html::e($backUrl) ?>">&larr; Back to the queue</a></p>
<?php else: ?>
    <form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
        <input type="hidden" name="bulk_action" value="reject">
        <?php foreach ($kept as $name => $value): ?>
            <input type="hidden" name="<?= Html::e($name) ?>" value="<?= Html::e($value) ?>">
        <?php endforeach; ?>

        <div class="lf-card">
            <h2>Send <?= count($stories) === 1 ? 'this story' : 'these ' . count($stories) . ' stories' ?> back?</h2>
            <ul>
                <?php foreach ($stories as $story): ?>
                    <li>
                        <?= Html::e($story->title) ?>
                        <input type="hidden" name="ids[]" value="<?= (int) $story->id ?>">
                    </li>
                <?php endforeach; ?>
            </ul>
            <p>Each becomes a draft again, and each author sees your reason on their own screens.</p>

            <div class="lf-field<?= isset($errors['reason']) ? ' lf-field--error' : '' ?>">
                <label for="reason">Reason for the authors</label>
                <textarea id="reason" name="reason" rows="6" maxlength="<?= (int) $limit ?>" required aria-describedby="reason_hint<?= isset($errors['reason']) ? ' reason_error' : '' ?>"><?= Html::e($reason) ?></textarea>
                <p class="lf-hint" id="reason_hint">The same reason goes to every author. Up to <?= number_format($limit) ?> characters.</p>
                <?php if (isset($errors['reason'])): ?><p class="lf-error" id="reason_error"><?= Html::e($errors['reason']) ?></p><?php endif; ?>
            </div>

            <p>
                <button type="submit" class="lf-button">Send back to the authors</button>
                <a href="<?= Html::e($backUrl) ?>">Cancel</a>
            </p>
        </div>
    </form>
<?php endif; ?>
