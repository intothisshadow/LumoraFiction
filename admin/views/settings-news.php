<?php

/**
 * Admin view: the news switch and the size of the home page block.
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

/** @var array{enabled: bool, home_count: int} $values */
/** @var array<string, string> $errors */
/** @var int $maxCount */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $newsUrl */
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
        <legend>News</legend>

        <div class="lf-choice">
            <input type="checkbox" id="enabled" name="enabled" value="1"<?= $values['enabled'] ? ' checked' : '' ?> aria-describedby="enabled_hint">
            <label for="enabled">Show news on the site</label>
            <p class="lf-hint" id="enabled_hint">When this is off, the News page, every news post address, the News menu link and the home page block are gone. Nothing is deleted: the posts and the <a href="<?= Html::e($newsUrl) ?>">News</a> screens in the admin stay as they are, and turning this back on brings everything back.</p>
        </div>

        <div class="lf-field<?= isset($errors['home_count']) ? ' lf-field--error' : '' ?>">
            <label for="home_count">Posts shown on the home page</label>
            <input type="text" inputmode="numeric" id="home_count" name="home_count" value="<?= Html::e((string) $values['home_count']) ?>" maxlength="2" aria-describedby="home_count_hint<?= isset($errors['home_count']) ? ' home_count_error' : '' ?>">
            <p class="lf-hint" id="home_count_hint">From 0 to <?= (int) $maxCount ?>. Use 0 to leave news off the home page; the News page still lists every published post. The usual choice is 3.</p>
            <?php if (isset($errors['home_count'])): ?><p class="lf-error" id="home_count_error"><?= Html::e($errors['home_count']) ?></p><?php endif; ?>
        </div>
    </fieldset>

    <p><button type="submit" class="lf-button">Save settings</button></p>
</form>
