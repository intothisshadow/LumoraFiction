<?php

/**
 * Asks which of the ticked classification terms to keep before the others are merged into it.
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
use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;

/** @var TermType $type */
/** @var list<Term> $terms the ticked terms, each offered as the one to keep */
/** @var array<int, string> $parents fandom names by id */
/** @var string|null $error */
/** @var string $search */
/** @var string $status */
/** @var int $page */
/** @var string $csrfToken */
/** @var string $formAction */
/** @var string $backUrl */

$plural = $labels->lowerPlural($type);
?>
<?php if ($error !== null): ?>
    <div class="lf-notice lf-notice--error" role="alert" id="lf-error-summary" tabindex="-1">
        <p><a href="#merge_keep"><?= Html::e($error) ?></a></p>
    </div>
<?php endif; ?>

<form method="post" action="<?= Html::e($formAction) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
    <input type="hidden" name="bulk_action" value="merge">
    <input type="hidden" name="confirmed" value="1">
    <input type="hidden" name="q" value="<?= Html::e($search) ?>">
    <input type="hidden" name="status" value="<?= Html::e($status) ?>">
    <input type="hidden" name="page" value="<?= (int) $page ?>">
    <?php foreach ($terms as $term): ?><input type="hidden" name="ids[]" value="<?= (int) $term->id ?>"><?php endforeach; ?>

    <fieldset class="lf-card" id="merge_keep">
        <legend>Merge <?= count($terms) ?> <?= Html::e($plural) ?></legend>
        <p>Choose the one to keep. Every story that uses one of the others will use it instead, and each merged name is kept as an alias of it, so the old names and addresses still lead there. This cannot be undone.</p>

        <?php foreach ($terms as $term): ?>
            <div class="lf-choice">
                <input type="radio" id="keep_<?= (int) $term->id ?>" name="target_id" value="<?= (int) $term->id ?>" required>
                <label for="keep_<?= (int) $term->id ?>">Keep <strong><?= Html::e($term->name) ?></strong><?= $type->belongsToFandom() ? ' (' . Html::e($parents[$term->parentId] ?? 'no fandom') . ')' : '' ?>, used by <?= (int) $term->storyCount ?> public <?= $term->storyCount === 1 ? 'story' : 'stories' ?></label>
            </div>
        <?php endforeach; ?>

        <?php if ($type->belongsToFandom()): ?>
            <p class="lf-hint">A term that belongs to a different fandom than the one you keep is left as it is.</p>
        <?php endif; ?>

        <p>
            <button type="submit" class="lf-button">Merge</button>
            <a href="<?= Html::e($backUrl) ?>">Cancel</a>
        </p>
    </fieldset>
</form>
