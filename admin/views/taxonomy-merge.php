<?php

/**
 * Merges one classification term into another.
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
use LumoraFiction\Models\Term;

/** @var Term $term */
/** @var string $target */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $backUrl */
?>
<?= $errorSummary ?>

<p><a href="<?= Html::e($backUrl) ?>">&larr; Back to <?= Html::e($term->name) ?></a></p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-card">
        <h2>Merge <?= Html::e($term->name) ?></h2>
        <p>Every story using &ldquo;<?= Html::e($term->name) ?>&rdquo; will use the term you name below instead, and &ldquo;<?= Html::e($term->name) ?>&rdquo; will be kept as an alias so it still leads there. This cannot be undone.</p>

        <div class="lf-field<?= isset($errors['target']) ? ' lf-field--error' : '' ?>">
            <label for="target">Merge into</label>
            <input type="text" id="target" name="target" value="<?= Html::e($target) ?>" maxlength="100" required aria-describedby="target_hint<?= isset($errors['target']) ? ' target_error' : '' ?>">
            <p class="lf-hint" id="target_hint">The name, or an alias, of the term to keep. It must be the same kind of term.</p>
            <?php if (isset($errors['target'])): ?><p class="lf-error" id="target_error"><?= Html::e($errors['target']) ?></p><?php endif; ?>
        </div>

        <p><button type="submit" class="lf-button">Merge</button></p>
    </div>
</form>
