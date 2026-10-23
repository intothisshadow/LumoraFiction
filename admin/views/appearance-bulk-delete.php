<?php

/**
 * Asks for confirmation before deleting themes.
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

use LumoraFiction\Core\Theme\ThemeInfo;
use LumoraFiction\Helpers\Html;

/** @var list<ThemeInfo> $themes only themes that can be deleted */
/** @var array<string, string> $kept themes in the selection that will stay, with the reason */
/** @var bool $anyBundled */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $backUrl */
?>
<form method="post" action="<?= Html::e($action) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
    <input type="hidden" name="bulk_action" value="delete">
    <input type="hidden" name="confirmed" value="1">

    <div class="lf-card">
        <h2>Delete <?= count($themes) === 1 ? 'this theme' : 'these ' . count($themes) . ' themes' ?>?</h2>
        <ul>
            <?php foreach ($themes as $theme): ?>
                <li>
                    <?= Html::e($theme->name) ?> (version <?= Html::e($theme->version) ?>)<?= $theme->bundled ? ' &mdash; comes with Lumora Fiction' : '' ?>
                    <input type="hidden" name="themes[]" value="<?= Html::e($theme->slug) ?>">
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($anyBundled): ?>
            <p class="lf-notice lf-notice--warning" role="status">At least one of these comes with Lumora Fiction. It will be put back the next time the site is updated, unless you remove it again.</p>
        <?php endif; ?>

        <p>The theme folders and the options saved for these themes are removed for good. This cannot be undone.</p>

        <?php if ($kept !== []): ?>
            <p class="lf-hint">Staying as they are:</p>
            <ul class="lf-hint">
                <?php foreach ($kept as $slug => $reason): ?>
                    <li><?= Html::e(mb_substr((string) $slug, 0, 50)) ?>: <?= Html::e($reason) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <p>
            <button type="submit" class="lf-button">Delete</button>
            <a href="<?= Html::e($backUrl) ?>">Cancel</a>
        </p>
    </div>
</form>
