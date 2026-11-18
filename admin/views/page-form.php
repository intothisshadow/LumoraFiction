<?php

/**
 * Admin view: the page form, with title, address, where the page is linked, search engine options and the text editor.
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

/** @var Page|null $page */
/** @var array{title: string, slug: string, content: string, content_format: string, in_menu: bool, in_footer: bool, sort_order: string, parent_id: string, description: string, noindex: bool} $values */
/** @var array<int, string> $parentChoices */
/** @var int $childCount */
/** @var string $editor trusted markup from the content editor */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $listUrl */
/** @var array<string, string> $urls */
/** @var array{title: int, slug: int, description: int} $limits */

$problem = static fn (string $name): string => isset($errors[$name]) ? '<p class="lf-error" id="' . Html::e($name) . '_error">' . Html::e($errors[$name]) . '</p>' : '';
$describe = static fn (string $name): string => ' aria-describedby="' . Html::e($name) . '_hint' . (isset($errors[$name]) ? ' ' . Html::e($name) . '_error' : '') . '"';
?>
<?= $errorSummary ?>

<p><a href="<?= Html::e($listUrl) ?>">&larr; All pages</a></p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form lf-form--wide" novalidate data-lf-guard>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-field<?= isset($errors['title']) ? ' lf-field--error' : '' ?>">
        <label for="title">Title</label>
        <input type="text" id="title" name="title" value="<?= Html::e($values['title']) ?>" maxlength="<?= (int) $limits['title'] ?>"<?= $describe('title') ?>>
        <p class="lf-hint" id="title_hint">What readers see at the top of the page and in the menu.</p>
        <?= $problem('title') ?>
    </div>

    <div class="lf-field<?= isset($errors['slug']) ? ' lf-field--error' : '' ?>">
        <label for="slug">Address</label>
        <input type="text" id="slug" name="slug" value="<?= Html::e($values['slug']) ?>" maxlength="<?= (int) $limits['slug'] ?>" autocapitalize="none" spellcheck="false"<?= $describe('slug') ?>>
        <p class="lf-hint" id="slug_hint">The last part of the page&rsquo;s web address, such as <code>about</code>. Lowercase letters, numbers and hyphens. Leave it empty to make it from the title.</p>
        <?= $problem('slug') ?>
    </div>

    <fieldset class="lf-card">
        <legend>Where it is linked</legend>

        <?php if ($childCount > 0): ?>
            <p class="lf-hint">This page has <?= (int) $childCount ?> <?= $childCount === 1 ? 'page' : 'pages' ?> under it in the main menu, so it cannot be put under another page. If you unpublish or delete it, <?= $childCount === 1 ? 'that page moves' : 'those pages move' ?> up to the top level of the menu and <?= $childCount === 1 ? 'is' : 'are' ?> listed on <?= $childCount === 1 ? 'its' : 'their' ?> own. Nothing is deleted with it.</p>
        <?php else: ?>
            <div class="lf-field<?= isset($errors['parent_id']) ? ' lf-field--error' : '' ?>">
                <label for="parent_id">Parent page</label>
                <select id="parent_id" name="parent_id"<?= $describe('parent_id') ?>>
                    <option value="">None (top level)</option>
                    <?php foreach ($parentChoices as $parentId => $parentTitle): ?>
                        <option value="<?= (int) $parentId ?>"<?= (string) $values['parent_id'] === (string) $parentId ? ' selected' : '' ?>><?= Html::e($parentTitle) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="lf-hint" id="parent_id_hint">Groups this page under another in the main menu, which opens a list of the pages beneath it. Only top-level pages are offered, because the menu has one level. If the parent is a draft or is not shown in the main menu, this page is listed on its own instead, so it is never hidden.</p>
                <?= $problem('parent_id') ?>
            </div>
        <?php endif; ?>

        <div class="lf-choice">
            <input type="checkbox" id="in_menu" name="in_menu" value="1"<?= $values['in_menu'] ? ' checked' : '' ?>>
            <label for="in_menu">Show in the main menu</label>
        </div>

        <div class="lf-choice">
            <input type="checkbox" id="in_footer" name="in_footer" value="1"<?= $values['in_footer'] ? ' checked' : '' ?>>
            <label for="in_footer">Show in the footer links</label>
        </div>

        <div class="lf-field<?= isset($errors['sort_order']) ? ' lf-field--error' : '' ?>">
            <label for="sort_order">Order</label>
            <input type="text" id="sort_order" name="sort_order" value="<?= Html::e($values['sort_order']) ?>" inputmode="numeric" maxlength="6"<?= $describe('sort_order') ?>>
            <p class="lf-hint" id="sort_order_hint">Pages are listed lowest number first, in the menu and in the footer. A page is only linked while it is published.</p>
            <?= $problem('sort_order') ?>
        </div>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Search engines</legend>

        <div class="lf-field<?= isset($errors['description']) ? ' lf-field--error' : '' ?>">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="2" maxlength="<?= (int) $limits['description'] ?>"<?= $describe('description') ?>><?= Html::e($values['description']) ?></textarea>
            <p class="lf-hint" id="description_hint">A sentence that search engines may show under the title. Up to <?= number_format($limits['description']) ?> characters. Optional.</p>
            <?= $problem('description') ?>
        </div>

        <div class="lf-choice">
            <input type="checkbox" id="noindex" name="noindex" value="1"<?= $values['noindex'] ? ' checked' : '' ?> aria-describedby="noindex_hint">
            <label for="noindex">Ask search engines not to list this page</label>
            <p class="lf-hint" id="noindex_hint">The page can still be opened by anyone who has the address.</p>
        </div>
    </fieldset>

    <?= $problem('format') ?>
    <?= $problem('content') ?>

    <?= $editor ?>
</form>

<?php if ($page !== null): ?>
    <p>
        <a href="<?= Html::e($urls['preview']) ?>">Preview this page</a> &middot;
        <?php if ($urls['public'] !== ''): ?><a href="<?= Html::e($urls['public']) ?>">View it on the site</a> &middot;<?php endif; ?>
        <a href="<?= Html::e($urls['delete']) ?>">Delete this page</a>
    </p>
<?php endif; ?>
