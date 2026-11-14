<?php

/**
 * Admin: confirm undoing an import.
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

use LumoraFiction\Helpers\Html;

/** @var array<string, mixed> $report */
/** @var array<string, string> $errors */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $backUrl */

$count = count($report['live']);
?>
<?php if ($errors !== []): ?>
    <div class="lf-notice lf-notice--error" role="alert" id="lf-error-summary" tabindex="-1">
        <p><?= Html::e((string) reset($errors)) ?></p>
    </div>
<?php endif; ?>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-card">
        <h2>Delete the <?= (int) $count ?> <?= $count === 1 ? 'story' : 'stories' ?> this import made?</h2>
        <p>This deletes every story the import made that still exists, with its chapters, even if you have edited or published it since. A series or collection the import made is deleted too when nothing else is in it. Fandoms, characters and tags the import proposed stay, because other stories may use them by now. This cannot be undone.</p>
        <p>
            <button type="submit" class="lf-button">Delete them</button>
            <a href="<?= Html::e($backUrl) ?>">Cancel</a>
        </p>
    </div>
</form>
