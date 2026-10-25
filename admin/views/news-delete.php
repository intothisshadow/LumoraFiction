<?php

/**
 * Admin view: the confirmation page for deleting a news post.
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
use LumoraFiction\Models\NewsPost;

/** @var NewsPost $post */
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
        <h2>Delete &ldquo;<?= Html::e($post->title) ?>&rdquo;?</h2>
        <p>This removes the news post for good<?= $post->isPublished() ? ', and readers can no longer open it' : '' ?>. This cannot be undone.<?= $post->isPublished() ? ' If you only want to hide it, unpublish it instead.' : '' ?></p>
        <p>
            <button type="submit" class="lf-button">Delete the post</button>
            <a href="<?= Html::e($backUrl) ?>">Cancel</a>
        </p>
    </div>
</form>
