<?php

/**
 * Admin view: how many stories each list on the home page shows.
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

/** @var array{published_count: int, updated_count: int} $values */
/** @var array<string, string> $errors */
/** @var int $maxCount */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $archiveSettingsUrl */

$lists = [
    'published_count' => ['label' => 'Recently published stories', 'hint' => 'The stories that were first published most recently.'],
    'updated_count' => ['label' => 'Recently updated stories', 'hint' => 'The stories that most recently got a new or changed chapter.'],
];
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

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <fieldset class="lf-card">
        <legend>Stories on the home page</legend>
        <p class="lf-hint">How many stories each list shows. A list set to 0 is left off the home page. Whether anyone can register is set under <a href="<?= Html::e($archiveSettingsUrl) ?>">Settings &gt; Archive</a>.</p>

        <?php foreach ($lists as $field => $list): ?>
            <div class="lf-field<?= isset($errors[$field]) ? ' lf-field--error' : '' ?>">
                <label for="<?= Html::e($field) ?>"><?= Html::e($list['label']) ?></label>
                <input type="text" inputmode="numeric" id="<?= Html::e($field) ?>" name="<?= Html::e($field) ?>" value="<?= Html::e((string) $values[$field]) ?>" maxlength="2" aria-describedby="<?= Html::e($field) ?>_hint<?= isset($errors[$field]) ? ' ' . Html::e($field) . '_error' : '' ?>">
                <p class="lf-hint" id="<?= Html::e($field) ?>_hint"><?= Html::e($list['hint']) ?> From 0 to <?= (int) $maxCount ?>; the usual choice is 8.</p>
                <?php if (isset($errors[$field])): ?><p class="lf-error" id="<?= Html::e($field) ?>_error"><?= Html::e($errors[$field]) ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </fieldset>

    <p><button type="submit" class="lf-button">Save settings</button></p>
</form>
