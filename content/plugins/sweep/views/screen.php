<?php

/**
 * Maintenance › Sweep.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;

/** @var string $step */
/** @var list<string> $tasks */
/** @var array<string, array{label: string, hint: string}> $definitions */
/** @var array<string, int> $counts */
/** @var array<string, list<string>> $samples */
/** @var array{unconfirmed_days: int, reminder_threshold: int} $values */
/** @var array<string, string> $errors */
/** @var int $maxDays */
/** @var int $maxThreshold */
/** @var string $previewToken */
/** @var string $runToken */
/** @var string $settingsToken */
/** @var string $previewAction */
/** @var string $runAction */
/** @var string $settingsAction */
/** @var string $backUrl */
/** @var string $retentionUrl */
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

<?php if ($step === 'preview'): ?>
    <form method="post" action="<?= Html::e($runAction) ?>" class="lf-form">
        <input type="hidden" name="csrf_token" value="<?= Html::e($runToken) ?>">

        <section class="lf-card" aria-labelledby="lf-sweep-confirm-heading">
            <h2 id="lf-sweep-confirm-heading">Remove these for good?</h2>
            <?php foreach ($tasks as $task): ?>
                <input type="hidden" name="tasks[]" value="<?= Html::e($task) ?>">
                <h3><?= Html::e($definitions[$task]['label']) ?>: <?= number_format($counts[$task]) ?></h3>
                <?php if ($counts[$task] === 0): ?>
                    <p class="lf-hint">Nothing to remove.</p>
                <?php elseif ($samples[$task] !== []): ?>
                    <ul>
                        <?php foreach ($samples[$task] as $line): ?><li><?= Html::e($line) ?></li><?php endforeach; ?>
                    </ul>
                    <?php if ($counts[$task] > count($samples[$task])): ?><p class="lf-hint">&hellip; and more.</p><?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
            <p>This cannot be undone. Take a backup first if you are not sure.</p>

            <div class="lf-choice">
                <input type="checkbox" id="confirm" name="confirm" value="1">
                <label for="confirm">Yes, remove them</label>
            </div>
            <p>
                <button type="submit" class="lf-button">Remove for good</button>
                <a href="<?= Html::e($backUrl) ?>">Cancel</a>
            </p>
        </section>
    </form>
<?php else: ?>
    <p class="lf-lead">Cleans out what a long-running archive no longer needs. Nothing is removed until you look at the list and confirm it, and nothing a story still uses is ever offered.</p>

    <form method="post" action="<?= Html::e($previewAction) ?>" class="lf-form">
        <input type="hidden" name="csrf_token" value="<?= Html::e($previewToken) ?>">

        <section class="lf-card" aria-labelledby="lf-sweep-heading">
            <h2 id="lf-sweep-heading">What can be cleaned</h2>
            <table class="lf-table">
                <thead>
                    <tr><th scope="col">Clean</th><th scope="col">Kind</th><th scope="col">Can be removed</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($definitions as $task => $definition): ?>
                        <tr>
                            <td><input type="checkbox" id="task_<?= Html::e($task) ?>" name="tasks[]" value="<?= Html::e($task) ?>"<?= $counts[$task] === 0 ? ' disabled' : '' ?> aria-label="Clean <?= Html::e(strtolower($definition['label'])) ?>"></td>
                            <th scope="row"><label for="task_<?= Html::e($task) ?>"><?= Html::e($definition['label']) ?></label><p class="lf-hint"><?= Html::e($definition['hint']) ?></p></th>
                            <td><?= number_format($counts[$task]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="lf-hint">The number of chapter versions and the days trash is kept are set under <a href="<?= Html::e($retentionUrl) ?>">Settings &gt; Retention</a>.</p>
        </section>

        <p><button type="submit" class="lf-button">Look at what will be removed</button></p>
    </form>

    <form method="post" action="<?= Html::e($settingsAction) ?>" class="lf-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= Html::e($settingsToken) ?>">

        <section class="lf-card" aria-labelledby="lf-sweep-settings-heading">
            <h2 id="lf-sweep-settings-heading">Settings</h2>

            <div class="lf-field<?= isset($errors['unconfirmed_days']) ? ' lf-field--error' : '' ?>">
                <label for="unconfirmed_days">Days before a never-confirmed account can be removed</label>
                <input type="text" inputmode="numeric" id="unconfirmed_days" name="unconfirmed_days" value="<?= Html::e((string) $values['unconfirmed_days']) ?>" maxlength="3" aria-describedby="unconfirmed_days_hint<?= isset($errors['unconfirmed_days']) ? ' unconfirmed_days_error' : '' ?>">
                <p class="lf-hint" id="unconfirmed_days_hint">From 1 to <?= (int) $maxDays ?>. An account that signed up and never followed its confirmation link. The usual choice is 14.</p>
                <?php if (isset($errors['unconfirmed_days'])): ?><p class="lf-error" id="unconfirmed_days_error"><?= Html::e($errors['unconfirmed_days']) ?></p><?php endif; ?>
            </div>

            <div class="lf-field<?= isset($errors['reminder_threshold']) ? ' lf-field--error' : '' ?>">
                <label for="reminder_threshold">Remind me when this many items can be removed</label>
                <input type="text" inputmode="numeric" id="reminder_threshold" name="reminder_threshold" value="<?= Html::e((string) $values['reminder_threshold']) ?>" maxlength="6" aria-describedby="reminder_threshold_hint<?= isset($errors['reminder_threshold']) ? ' reminder_threshold_error' : '' ?>">
                <p class="lf-hint" id="reminder_threshold_hint">From 0 to <?= (int) $maxThreshold ?>. Puts a note in each Administrator&rsquo;s notifications, checked about once a week. Use 0 for no reminder.</p>
                <?php if (isset($errors['reminder_threshold'])): ?><p class="lf-error" id="reminder_threshold_error"><?= Html::e($errors['reminder_threshold']) ?></p><?php endif; ?>
            </div>
        </section>

        <p><button type="submit" class="lf-button">Save settings</button></p>
    </form>
<?php endif; ?>
