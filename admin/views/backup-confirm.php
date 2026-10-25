<?php

/**
 * Admin view: the question asked before a backup is restored or deleted.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;

/** @var array<string, mixed> $backup */
/** @var bool $complete */
/** @var string $action "restore" or "delete" */
/** @var string $csrfToken */
/** @var string $formAction */
/** @var string $backUrl */

$made = (string) ($backup['created_at'] ?? '') . ' UTC';
?>
<form method="post" action="<?= Html::e($formAction) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-card">
        <?php if ($action === 'restore'): ?>
            <h2>Restore the backup from <?= Html::e($made) ?>?</h2>
            <p>The site goes back to version <?= Html::e((string) ($backup['app_version'] ?? '')) ?> as it was at that moment. <strong>Everything written since then is lost</strong>: stories, chapters, comments, accounts and settings made after the backup are replaced by what the backup holds.</p>
            <p>Uploaded files and your configuration file are not touched. A backup of the site as it is now is made first, and visitors see the maintenance page while this runs.</p>
            <p>
                <button type="submit" class="lf-button">Restore this backup</button>
                <a href="<?= Html::e($backUrl) ?>">Cancel</a>
            </p>
        <?php else: ?>
            <h2>Delete the backup from <?= Html::e($made) ?>?</h2>
            <p>Its files ZIP and database copy are removed from the server. This cannot be undone<?= $complete ? ', so download them first if you want to keep them' : '' ?>.</p>
            <p>
                <button type="submit" class="lf-button">Delete this backup</button>
                <a href="<?= Html::e($backUrl) ?>">Cancel</a>
            </p>
        <?php endif; ?>
    </div>
</form>
