<?php

/**
 * Redirects: the list, with a form to add one.
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

use LumoraFiction\Core\Admin\BulkBar;
use LumoraFiction\Helpers\Html;

/** @var list<array{id: int, source: string, target: string, status: int, automatic: bool, created_at: string}> $redirects */
/** @var array{source: string, target: string, status: string} $values */
/** @var array<string, string> $errors */
/** @var array<int, string> $statuses */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var string $listUrl */
/** @var string $deleteBase */
/** @var string $searchUrl */
/** @var string $csrfToken */
/** @var string $bulkUrl */
/** @var string $assetVersion */

$bulkForm = 'lf-bulk-form';
?>
<?php if ($errors !== []): ?>
    <div class="lf-notice lf-notice--error" role="alert" id="lf-error-summary" tabindex="-1">
        <p>Please fix the following:</p>
        <ul>
            <?php foreach ($errors as $field => $message): ?>
                <li><?= $field === 'form' ? Html::e($message) : '<a href="#' . Html::e($field) . '">' . Html::e($message) . '</a>' ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<p class="lf-lead">A redirect sends visitors, and search engines, from an address that no longer works to the one that does. When a page's address changes, or a classification term is merged into another, the old address is redirected for you. A redirect is used only where the site has nothing to show, so it never hides a page that is there. See also <a href="<?= Html::e($searchUrl) ?>">Search engines</a>.</p>

<form method="post" action="<?= Html::e($listUrl) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <section class="lf-card" aria-labelledby="lf-redirect-add">
        <h2 id="lf-redirect-add">Add a redirect</h2>

        <div class="lf-field<?= isset($errors['source']) ? ' lf-field--error' : '' ?>">
            <label for="source">From</label>
            <input type="text" id="source" name="source" value="<?= Html::e($values['source']) ?>" maxlength="400" autocomplete="off" spellcheck="false" aria-describedby="source_hint<?= isset($errors['source']) ? ' source_error' : '' ?>">
            <p class="lf-hint" id="source_hint">The old address on this site, for example <code>/old-page</code> or <code>/story/12-old-title</code>.</p>
            <?php if (isset($errors['source'])): ?><p class="lf-error" id="source_error"><?= Html::e($errors['source']) ?></p><?php endif; ?>
        </div>

        <div class="lf-field<?= isset($errors['target']) ? ' lf-field--error' : '' ?>">
            <label for="target">To</label>
            <input type="text" id="target" name="target" value="<?= Html::e($values['target']) ?>" maxlength="500" autocomplete="off" spellcheck="false" aria-describedby="target_hint<?= isset($errors['target']) ? ' target_error' : '' ?>">
            <p class="lf-hint" id="target_hint">A path on this site, or a full web address starting with https://.</p>
            <?php if (isset($errors['target'])): ?><p class="lf-error" id="target_error"><?= Html::e($errors['target']) ?></p><?php endif; ?>
        </div>

        <div class="lf-field<?= isset($errors['status']) ? ' lf-field--error' : '' ?>">
            <label for="status">Kind</label>
            <select id="status" name="status">
                <?php foreach ($statuses as $code => $label): ?>
                    <option value="<?= (int) $code ?>"<?= $values['status'] === (string) $code ? ' selected' : '' ?>><?= Html::e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['status'])): ?><p class="lf-error"><?= Html::e($errors['status']) ?></p><?php endif; ?>
        </div>

        <p><button type="submit" class="lf-button">Add the redirect</button></p>
    </section>
</form>

<?php if ($redirects === []): ?>
    <p class="lf-hint">There are no redirects yet.</p>
<?php else: ?>
    <p class="lf-hint"><?= (int) $total ?> in all.</p>
    <?= BulkBar::form($bulkForm, $bulkUrl, $csrfToken, ['page' => $page > 1 ? (string) $page : '']) ?>
    <?= BulkBar::bar($bulkForm, 'redirects', ['delete' => 'Delete…', 'permanent' => 'Make permanent', 'temporary' => 'Make temporary']) ?>

    <table class="lf-table lf-table--selectable">
        <caption class="lf-visually-hidden">Redirects</caption>
        <thead>
            <tr>
                <?= BulkBar::selectAllHeader('redirect_ids', $bulkForm, 'redirects') ?>
                <th scope="col">From</th>
                <th scope="col">To</th>
                <th scope="col">Kind</th>
                <th scope="col">Added</th>
                <th scope="col"><span class="lf-visually-hidden">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($redirects as $redirect): ?>
                <tr>
                    <?= BulkBar::checkbox('redirect_ids', $bulkForm, $redirect['id'], 'Select the redirect from ' . $redirect['source']) ?>
                    <th scope="row"><code><?= Html::e($redirect['source']) ?></code></th>
                    <td><code><?= Html::e($redirect['target']) ?></code></td>
                    <td><?= Html::e($statuses[$redirect['status']] ?? (string) $redirect['status']) ?><?= $redirect['automatic'] ? ' <span class="lf-status">Automatic</span>' : '' ?></td>
                    <td><time datetime="<?= Html::e(str_replace(' ', 'T', $redirect['created_at'])) ?>"><?= Html::e(substr($redirect['created_at'], 0, 10)) ?></time></td>
                    <td>
                        <form method="post" action="<?= Html::e($deleteBase . '/' . $redirect['id'] . '/delete') ?>" class="lf-inline-form">
                            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                            <button type="submit" class="lf-button lf-button--secondary">Delete<span class="lf-visually-hidden"> the redirect from <?= Html::e($redirect['source']) ?></span></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <nav class="lf-pagination" aria-label="Pages">
            <?php if ($page > 1): ?><a href="<?= Html::e($listUrl . '?page=' . ($page - 1)) ?>" rel="prev">Previous</a><?php endif; ?>
            <span>Page <?= (int) $page ?> of <?= (int) $pages ?></span>
            <?php if ($page < $pages): ?><a href="<?= Html::e($listUrl . '?page=' . ($page + 1)) ?>" rel="next">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>

    <?= BulkBar::script($assetVersion) ?>
<?php endif; ?>
