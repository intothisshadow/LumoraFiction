<?php

/**
 * Classification: the kinds of term, how many each has, and which kinds a story must have.
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

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\TermType;

/** @var \LumoraFiction\Services\ClassificationLabels $labels */
/** @var array<string, array{singular: string, plural: string}> $labelValues what the form shows for each kind */
/** @var string $labelsAction */
/** @var list<TermType> $types */
/** @var list<TermType> $hiddenTypes */
/** @var array<string, array{total: int, unreviewed: int}> $counts */
/** @var list<string> $required */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $baseUrl */
?>
<?= $errorSummary ?>

<p class="lf-lead">The lists writers choose from when they classify a story. Writers can add new fandoms, characters, relationships, and tags while writing; those wait here, marked as unapproved, until you approve or merge them. Ratings, archive warnings, relationship categories and story status can be filled from ready-made <a href="<?= Html::e(AdminUrl::to('settings/archive')) ?>#lf-presets-heading">starter lists</a> under Settings.</p>

<table class="lf-table lf-table--lists">
    <caption class="lf-visually-hidden">Classification lists</caption>
    <thead>
        <tr>
            <th scope="col">List</th>
            <th scope="col">Terms</th>
            <th scope="col">Unapproved</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($types as $type): ?>
            <?php $row = $counts[$type->value] ?? ['total' => 0, 'unreviewed' => 0]; ?>
            <tr>
                <th scope="row"><a href="<?= Html::e($baseUrl . '/' . $type->value) ?>"><?= Html::e($labels->plural($type)) ?></a></th>
                <td><?= (int) $row['total'] ?></td>
                <td>
                    <?php if ($row['unreviewed'] > 0): ?>
                        <a href="<?= Html::e($baseUrl . '/' . $type->value . '?status=unapproved') ?>"><?= (int) $row['unreviewed'] ?></a>
                    <?php else: ?>
                        0
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if ($hiddenTypes !== []): ?>
    <p class="lf-hint">Hidden by the archive setting, and kept as they are: <?= Html::e(implode(', ', array_map(static fn (TermType $type): string => $labels->lowerPlural($type), $hiddenTypes))) ?>.</p>
<?php endif; ?>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <fieldset class="lf-card">
        <legend>Required before a story is submitted</legend>
        <p class="lf-hint">Drafts can be saved without them. Apart from the rating, a list with nothing on it is never required, because nobody could fill it in.</p>
        <?php foreach ($types as $type): ?>
            <?php $always = $type === TermType::Rating; ?>
            <div class="lf-choice">
                <input type="checkbox" id="required_<?= Html::e($type->value) ?>" name="required_<?= Html::e($type->value) ?>" value="1"<?= $always || in_array($type->value, $required, true) ? ' checked' : '' ?><?= $always ? ' disabled aria-describedby="required_rating_hint"' : '' ?>>
                <label for="required_<?= Html::e($type->value) ?>"><?= Html::e($labels->singular($type)) ?></label>
                <?php if ($always): ?><p class="lf-hint" id="required_rating_hint">Always required. A rating tells readers who a story suits, so every story needs one before it is published.</p><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </fieldset>

    <p><button type="submit" class="lf-button">Save required fields</button></p>
</form>

<form method="post" action="<?= Html::e($labelsAction) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <fieldset class="lf-card">
        <legend>Names of the lists</legend>
        <p class="lf-hint">Call a list what your readers call it, for example &ldquo;Pairing&rdquo; instead of &ldquo;Relationship&rdquo;. Only the words change: web addresses, feeds and saved settings keep working. Fill in both the singular and the plural, or leave both empty to use the usual words.</p>
        <table class="lf-table lf-table--labels">
            <caption class="lf-visually-hidden">Names of the lists</caption>
            <thead>
                <tr>
                    <th scope="col">List</th>
                    <th scope="col">Singular</th>
                    <th scope="col">Plural</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($types as $type): ?>
                    <?php
                    $entry = $labelValues[$type->value] ?? ['singular' => '', 'plural' => ''];
                    $field = 'label_' . $type->value;
                    ?>
                    <tr>
                        <th scope="row"><?= Html::e($type->label()) ?></th>
                        <td>
                            <label class="lf-visually-hidden" for="<?= Html::e($field) ?>_singular">Singular name for <?= Html::e(strtolower($type->label())) ?></label>
                            <input type="text" id="<?= Html::e($field) ?>_singular" name="<?= Html::e($field) ?>_singular" value="<?= Html::e($entry['singular']) ?>" placeholder="<?= Html::e($type->label()) ?>" maxlength="40"<?= isset($errors[$field . '_singular']) ? ' aria-invalid="true" aria-describedby="' . Html::e($field) . '_error"' : '' ?>>
                        </td>
                        <td>
                            <label class="lf-visually-hidden" for="<?= Html::e($field) ?>_plural">Plural name for <?= Html::e(strtolower($type->label())) ?></label>
                            <input type="text" id="<?= Html::e($field) ?>_plural" name="<?= Html::e($field) ?>_plural" value="<?= Html::e($entry['plural']) ?>" placeholder="<?= Html::e($type->pluralLabel()) ?>" maxlength="40">
                            <?php if (isset($errors[$field . '_singular'])): ?><p class="lf-error" id="<?= Html::e($field) ?>_error"><?= Html::e($errors[$field . '_singular']) ?></p><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </fieldset>

    <p><button type="submit" class="lf-button">Save names</button></p>
</form>
