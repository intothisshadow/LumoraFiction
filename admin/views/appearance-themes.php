<?php

/**
 * Appearance › Themes: the installed themes and which one the site uses.
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

/** @var list<ThemeInfo> $themes */
/** @var string $activeSlug */
/** @var string $chosenMissing */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $optionsUrl */
?>
<?php if ($chosenMissing !== ''): ?>
    <div class="lf-notice lf-notice--warning" role="status">
        <p>The theme you chose (<?= Html::e($chosenMissing) ?>) is not installed or cannot be read, so the built-in theme is being used. Put the theme&rsquo;s folder back in the themes folder, or choose another theme below.</p>
    </div>
<?php endif; ?>

<p class="lf-lead">Themes control how the public side of the site looks. Themes are installed by copying their folder into the themes folder of the site.</p>

<ul class="lf-theme-list">
    <?php foreach ($themes as $theme): ?>
        <?php $isActive = $theme->slug === $activeSlug; ?>
        <li class="lf-card lf-theme-card<?= $isActive ? ' lf-theme-card--active' : '' ?>">
            <h2 class="lf-theme-card__name"><?= Html::e($theme->name) ?><?= $isActive ? ' <span class="lf-badge">Active</span>' : '' ?></h2>
            <p class="lf-hint">
                Version <?= Html::e($theme->version) ?><?= $theme->author !== '' ? ' &middot; by ' . Html::e($theme->author) : '' ?>
            </p>
            <?php if ($theme->description !== ''): ?><p class="lf-theme-card__description"><?= Html::e($theme->description) ?></p><?php endif; ?>

            <?php if ($isActive): ?>
                <p><a class="lf-button lf-button--quiet" href="<?= Html::e($optionsUrl) ?>">Theme options</a></p>
            <?php else: ?>
                <form method="post" action="<?= Html::e($action) ?>">
                    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                    <input type="hidden" name="theme" value="<?= Html::e($theme->slug) ?>">
                    <button type="submit" class="lf-button">Use this theme</button>
                </form>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
