<?php

/**
 * Admin view: the news post form, a title, a pin switch, and the text editor with its Save and Publish buttons.
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

/** @var NewsPost|null $post */
/** @var array{title: string, content: string, content_format: string, pinned: bool} $values */
/** @var string $editor trusted markup from the content editor */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $listUrl */
/** @var array<string, string> $urls */
/** @var int $titleMax */

$problem = static fn (string $name): string => isset($errors[$name]) ? '<p class="lf-error" id="' . Html::e($name) . '_error">' . Html::e($errors[$name]) . '</p>' : '';
?>
<?= $errorSummary ?>

<p><a href="<?= Html::e($listUrl) ?>">&larr; All news posts</a></p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form lf-form--wide" novalidate data-lf-guard>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-field<?= isset($errors['title']) ? ' lf-field--error' : '' ?>">
        <label for="title">Title</label>
        <input type="text" id="title" name="title" value="<?= Html::e($values['title']) ?>" maxlength="<?= (int) $titleMax ?>" aria-describedby="title_hint<?= isset($errors['title']) ? ' title_error' : '' ?>">
        <p class="lf-hint" id="title_hint">What readers see at the top of the post and in the list.</p>
        <?= $problem('title') ?>
    </div>

    <div class="lf-choice">
        <input type="checkbox" id="pinned" name="pinned" value="1"<?= $values['pinned'] ? ' checked' : '' ?> aria-describedby="pinned_hint">
        <label for="pinned">Pin to the top</label>
        <p class="lf-hint" id="pinned_hint">Pinned posts are listed before newer ones, on the home page and on the News page.</p>
    </div>

    <?= $problem('format') ?>
    <?= $problem('content') ?>

    <?= $editor ?>
</form>

<?php if ($post !== null): ?>
    <p>
        <a href="<?= Html::e($urls['preview']) ?>">Preview this post</a> &middot;
        <a href="<?= Html::e($urls['delete']) ?>">Delete this post</a>
    </p>
<?php endif; ?>
