<?php

/**
 * Asks for confirmation before deleting a story and its chapters.
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
/** @var int $chapterCount */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $backUrl */
?>
<?= $errorSummary ?>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-card">
        <h2>Delete &ldquo;<?= Html::e($story->title) ?>&rdquo;?</h2>
        <p>The story<?= $chapterCount > 0 ? ' and its ' . (int) $chapterCount . ($chapterCount === 1 ? ' chapter' : ' chapters') : '' ?> will be removed for good, together with the classification it carries. This cannot be undone.</p>
        <p>
            <button type="submit" class="lf-button">Delete the story</button>
            <a href="<?= Html::e($backUrl) ?>">Cancel</a>
        </p>
    </div>
</form>
