<?php

/**
 * Settings › Import.
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

/** @var array{import_enabled: bool, import_staff_only: bool} $values */
/** @var array<string, string> $errors */
/** @var string $csrfToken */
/** @var string $action */
?>
<?php if ($errors !== []): ?>
    <div class="lf-notice lf-notice--error" role="alert" id="lf-error-summary" tabindex="-1">
        <p>Please fix the following:</p>
        <ul>
            <?php foreach ($errors as $field => $message): ?>
                <li><?= Html::e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<p class="lf-lead">Writers who come from another story archive can bring their own stories across from the HTML download of each work, from Archive of Our Own, Squidgeworld or any other site that runs the same software. Nothing is fetched from the other site: the writer uploads the file. Every story is read, shown in a preview and only written once the writer confirms it, and it goes through the same rules as a story written here, including the approval of a new writer&rsquo;s first story and the rating every story needs.</p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <fieldset class="lf-card">
        <legend>Importing stories</legend>
        <div class="lf-choice">
            <input type="checkbox" id="import_enabled" name="import_enabled" value="1"<?= $values['import_enabled'] ? ' checked' : '' ?>>
            <label for="import_enabled">Let writers import their own stories</label>
        </div>
        <p class="lf-hint">Switching this off hides the Import screens. Stories already imported stay, and so do their reports.</p>
        <div class="lf-choice">
            <input type="checkbox" id="import_staff_only" name="import_staff_only" value="1"<?= $values['import_staff_only'] ? ' checked' : '' ?>>
            <label for="import_staff_only">Only Editors and Administrators may import</label>
        </div>
        <p class="lf-hint">Use this when you would rather bring writers&rsquo; stories across for them.</p>
    </fieldset>

    <div class="lf-notice" role="note">
        <p>Each file&rsquo;s rating is matched to your ratings by name first, then by how suitable it is for younger readers, and the writer can change every pairing before importing. To make a rating from another site always land on one of yours, add it as an alias of that rating under Classification.</p>
    </div>

    <p><button type="submit" class="lf-button">Save settings</button></p>
</form>
