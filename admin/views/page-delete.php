<?php

/**
 * Admin view: the confirmation shown before a static page is deleted.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\Page;

/** @var Page $page */
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
        <h2>Delete &ldquo;<?= Html::e($page->title) ?>&rdquo;?</h2>
        <p>This removes the page for good<?= $page->isPublished() ? ', and readers can no longer open it. Its links leave the menu and the footer' : '' ?>. This cannot be undone.<?= $page->isPublished() ? ' If you only want to hide it, unpublish it instead.' : '' ?></p>
        <p>
            <button type="submit" class="lf-button">Delete the page</button>
            <a href="<?= Html::e($backUrl) ?>">Cancel</a>
        </p>
    </div>
</form>
