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

use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\TermType;

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

<p class="lf-lead">The lists writers choose from when they classify a story. Writers can add new fandoms, characters, relationships, and tags while writing; those wait here, marked as not yet reviewed, until you approve or merge them.</p>

<table class="lf-table lf-table--lists">
    <caption class="lf-visually-hidden">Classification lists</caption>
    <thead>
        <tr>
            <th scope="col">List</th>
            <th scope="col">Terms</th>
            <th scope="col">Not yet reviewed</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($types as $type): ?>
            <?php $row = $counts[$type->value] ?? ['total' => 0, 'unreviewed' => 0]; ?>
            <tr>
                <th scope="row"><a href="<?= Html::e($baseUrl . '/' . $type->value) ?>"><?= Html::e($type->pluralLabel()) ?></a></th>
                <td><?= (int) $row['total'] ?></td>
                <td>
                    <?php if ($row['unreviewed'] > 0): ?>
                        <a href="<?= Html::e($baseUrl . '/' . $type->value . '?unreviewed=1') ?>"><?= (int) $row['unreviewed'] ?></a>
                    <?php else: ?>
                        0
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if ($hiddenTypes !== []): ?>
    <p class="lf-hint">Hidden by the archive setting, and kept as they are: <?= Html::e(implode(', ', array_map(static fn (TermType $type): string => strtolower($type->pluralLabel()), $hiddenTypes))) ?>.</p>
<?php endif; ?>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <fieldset class="lf-card">
        <legend>Required before a story is submitted</legend>
        <p class="lf-hint">Drafts can be saved without them. A list with nothing on it is never required, because nobody could fill it in.</p>
        <?php foreach ($types as $type): ?>
            <div class="lf-choice">
                <input type="checkbox" id="required_<?= Html::e($type->value) ?>" name="required_<?= Html::e($type->value) ?>" value="1"<?= in_array($type->value, $required, true) ? ' checked' : '' ?>>
                <label for="required_<?= Html::e($type->value) ?>"><?= Html::e($type->label()) ?></label>
            </div>
        <?php endforeach; ?>
    </fieldset>

    <p><button type="submit" class="lf-button">Save required fields</button></p>
</form>
