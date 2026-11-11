<?php

/**
 * Admin: confirm deleting a collection.
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
use LumoraFiction\Models\Collection;

/** @var Collection $collection */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $backUrl */
?>
<?= $errorSummary ?>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-card">
        <h2>Delete &ldquo;<?= Html::e($collection->title) ?>&rdquo;?</h2>
        <p>This removes the collection and its page. The stories in it, and the authors who offered them, are not changed. This cannot be undone.</p>
        <p>
            <button type="submit" class="lf-button">Delete the collection</button>
            <a href="<?= Html::e($backUrl) ?>">Cancel</a>
        </p>
    </div>
</form>
