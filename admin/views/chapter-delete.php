<?php

/**
 * Asks for confirmation before deleting a chapter.
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
use LumoraFiction\Models\Chapter;
use LumoraFiction\Models\Story;

/** @var Story $story */
/** @var Chapter $chapter */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $backUrl */

$name = $chapter->title !== '' ? $chapter->title : 'Chapter ' . $chapter->number;
?>
<?= $errorSummary ?>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-card">
        <h2>Delete &ldquo;<?= Html::e($name) ?>&rdquo;?</h2>
        <p>This removes chapter <?= (int) $chapter->number ?> (<?= number_format($chapter->wordCount) ?> words) from &ldquo;<?= Html::e($story->title) ?>&rdquo; for good. The chapters after it move up by one. This cannot be undone.</p>
        <p>
            <button type="submit" class="lf-button">Delete the chapter</button>
            <a href="<?= Html::e($backUrl) ?>">Cancel</a>
        </p>
    </div>
</form>
