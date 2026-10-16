<?php

/**
 * The chapter editor: a textarea that the editor script upgrades to the writer's chosen mode.
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

/** @var string $id */
/** @var string $name */
/** @var string $label */
/** @var int $rows */
/** @var string $content */
/** @var string $mode */
/** @var string $format */
/** @var list<array{value: string, label: string, format: string}> $modes */
/** @var list<array{name: string, value: string, label: string, primary?: bool}> $actions */
/** @var string $token */
/** @var string $previewUrl */
/** @var string $convertUrl */
/** @var string $tinymceBase */
/** @var string $contentCss */
/** @var list<string> $scripts */
/** @var list<string> $styles */
?>
<?php foreach ($styles as $style): ?>
    <link rel="stylesheet" href="<?= Html::e($style) ?>">
<?php endforeach; ?>
<div class="lf-editor" data-lf-editor
     data-lf-mode="<?= Html::e($mode) ?>"
     data-lf-token="<?= Html::e($token) ?>"
     data-lf-preview-url="<?= Html::e($previewUrl) ?>"
     data-lf-convert-url="<?= Html::e($convertUrl) ?>"
     data-lf-tinymce-base="<?= Html::e($tinymceBase) ?>"
     data-lf-content-css="<?= Html::e($contentCss) ?>">
    <div class="lf-editor__bar">
        <?php if (count($modes) > 1): ?>
            <div class="lf-editor__modes" role="group" aria-label="Editor" data-lf-modes hidden>
                <?php foreach ($modes as $option): ?>
                    <button type="button" class="lf-button lf-button--quiet lf-editor__mode" data-lf-mode-button="<?= Html::e($option['value']) ?>" data-lf-format="<?= Html::e($option['format']) ?>" aria-pressed="<?= $option['value'] === $mode ? 'true' : 'false' ?>"><?= Html::e($option['label']) ?></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="lf-editor__counts" aria-live="off"><span data-lf-words>0</span> words &middot; <span data-lf-characters>0</span> characters</p>
    </div>

    <label class="lf-editor__label" for="<?= Html::e($id) ?>"><?= Html::e($label) ?></label>
    <textarea id="<?= Html::e($id) ?>" name="<?= Html::e($name) ?>" rows="<?= (int) $rows ?>" class="lf-editor__textarea" data-lf-textarea><?= Html::e($content) ?></textarea>
    <input type="hidden" name="<?= Html::e($name) ?>_format" value="<?= Html::e($format) ?>" data-lf-format-field>

    <p class="lf-editor__status" role="status" data-lf-status></p>

    <div class="lf-editor__actions">
        <?php foreach ($actions as $action): ?>
            <button type="submit" name="<?= Html::e($action['name']) ?>" value="<?= Html::e($action['value']) ?>" class="lf-button<?= !empty($action['primary']) ? '' : ' lf-button--secondary' ?>"><?= Html::e($action['label']) ?></button>
        <?php endforeach; ?>
        <button type="button" class="lf-button lf-button--quiet" data-lf-preview-button hidden>Preview</button>
    </div>

    <section class="lf-editor__preview" aria-labelledby="<?= Html::e($id) ?>-preview-title" data-lf-preview hidden>
        <h2 id="<?= Html::e($id) ?>-preview-title" tabindex="-1">Preview</h2>
        <div class="lf-editor__preview-body" data-lf-preview-body></div>
    </section>
</div>
<?php foreach ($scripts as $script): ?>
    <script src="<?= Html::e($script) ?>" defer></script>
<?php endforeach; ?>
