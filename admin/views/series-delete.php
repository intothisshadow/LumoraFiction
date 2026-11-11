<?php

/**
 * Admin: confirm deleting a series.
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
use LumoraFiction\Models\Series;

/** @var Series $series */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $backUrl */
?>
<?= $errorSummary ?>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-card">
        <h2>Delete &ldquo;<?= Html::e($series->title) ?>&rdquo;?</h2>
        <p>This removes the series and its page. The stories in it are not deleted or changed: they simply stop being parts of a series. This cannot be undone.</p>
        <p>
            <button type="submit" class="lf-button">Delete the series</button>
            <a href="<?= Html::e($backUrl) ?>">Cancel</a>
        </p>
    </div>
</form>
