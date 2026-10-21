<?php

/**
 * Maintenance › Plugins: the installed plugins and whether each is on.
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

use LumoraFiction\Core\Plugin\PluginInfo;
use LumoraFiction\Helpers\Html;

/** @var list<array{slug: string, info: PluginInfo|null, active: bool, failure: string, problems: list<string>}> $plugins */
/** @var string $csrfToken */
/** @var string $activateAction */
/** @var string $deactivateAction */
?>
<p class="lf-lead">Plugins add features to the site. Install one by copying its folder into the plugins folder of the site, then turn it on here. Only turn on plugins from sources you trust: a plugin can do anything the site itself can.</p>

<?php if ($plugins === []): ?>
    <div class="lf-card">
        <p>No plugins are installed.</p>
    </div>
<?php else: ?>
    <ul class="lf-plugin-list">
        <?php foreach ($plugins as $plugin): ?>
            <?php $info = $plugin['info']; ?>
            <li class="lf-card lf-plugin-card<?= $plugin['active'] ? ' lf-plugin-card--active' : '' ?>">
                <h2 class="lf-plugin-card__name">
                    <?= Html::e($info !== null ? $info->name : $plugin['slug']) ?>
                    <?php if ($plugin['active']): ?><span class="lf-badge">On</span><?php endif; ?>
                </h2>

                <?php if ($info !== null): ?>
                    <p class="lf-hint">
                        Version <?= Html::e($info->version) ?><?= $info->author !== '' ? ' &middot; by ' . Html::e($info->author) : '' ?>
                    </p>
                    <?php if ($info->description !== ''): ?><p class="lf-plugin-card__description"><?= Html::e($info->description) ?></p><?php endif; ?>
                    <?php if ($info->requiresPhp !== '' || $info->requiresApp !== ''): ?>
                        <p class="lf-hint">Needs <?= Html::e(implode(' and ', array_filter([
                            $info->requiresPhp !== '' ? 'PHP ' . $info->requiresPhp . '+' : '',
                            $info->requiresApp !== '' ? 'Lumora Fiction ' . $info->requiresApp . '+' : '',
                        ]))) ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="lf-hint">This plugin is turned on, but its folder is missing from the plugins folder.</p>
                <?php endif; ?>

                <?php foreach ($plugin['problems'] as $problem): ?>
                    <p class="lf-notice lf-notice--warning" role="status"><?= Html::e($problem) ?></p>
                <?php endforeach; ?>

                <?php if ($plugin['failure'] !== ''): ?>
                    <p class="lf-notice lf-notice--error" role="status">It could not be loaded and was skipped: <?= Html::e($plugin['failure']) ?></p>
                <?php endif; ?>

                <?php if ($plugin['active']): ?>
                    <form method="post" action="<?= Html::e($deactivateAction) ?>">
                        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                        <input type="hidden" name="plugin" value="<?= Html::e($plugin['slug']) ?>">
                        <button type="submit" class="lf-button lf-button--secondary">Turn off</button>
                    </form>
                <?php elseif ($plugin['problems'] === []): ?>
                    <form method="post" action="<?= Html::e($activateAction) ?>">
                        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                        <input type="hidden" name="plugin" value="<?= Html::e($plugin['slug']) ?>">
                        <button type="submit" class="lf-button">Turn on</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
