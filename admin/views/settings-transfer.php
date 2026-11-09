<?php

/**
 * Export and import settings: the two forms and, after a file is read, the list of what it would change.
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

/** @var array{preview: array{errors: list<string>, notes: list<string>, rows: list<array{group: string, field: string, from: string, to: string}>}, payload: string}|null $import */
/** @var array<string, string> $errors */
/** @var string $exportAction */
/** @var string $previewAction */
/** @var string $applyAction */
/** @var string $cancelUrl */
/** @var int $maxKilobytes */
/** @var string $csrfToken */

$preview = $import['preview'] ?? null;
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

<?php if ($preview !== null): ?>
    <section class="lf-card" aria-labelledby="lf-preview-heading">
        <h2 id="lf-preview-heading">What this file would change</h2>

        <?php if ($preview['errors'] !== []): ?>
            <div class="lf-notice lf-notice--error" role="alert">
                <p>This file cannot be imported, and nothing has been changed:</p>
                <ul>
                    <?php foreach ($preview['errors'] as $message): ?><li><?= Html::e($message) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php foreach ($preview['notes'] as $note): ?>
            <p class="lf-notice lf-notice--info"><?= Html::e($note) ?></p>
        <?php endforeach; ?>

        <?php if ($preview['rows'] === []): ?>
            <p class="lf-hint"><?= $preview['errors'] === [] ? 'Every setting in this file is already the same here, so there is nothing to change.' : 'Nothing in the file would change a setting.' ?></p>
        <?php else: ?>
            <table class="lf-table">
                <caption class="lf-visually-hidden">Settings the file would change</caption>
                <thead>
                    <tr>
                        <th scope="col">Group</th>
                        <th scope="col">Setting</th>
                        <th scope="col">Now</th>
                        <th scope="col">After import</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview['rows'] as $row): ?>
                        <tr>
                            <td><?= Html::e($row['group']) ?></td>
                            <th scope="row"><?= Html::e($row['field']) ?></th>
                            <td><?= Html::e($row['from']) ?></td>
                            <td><?= Html::e($row['to']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($preview['errors'] === [] && $preview['rows'] !== []): ?>
            <form method="post" action="<?= Html::e($applyAction) ?>" class="lf-form">
                <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                <input type="hidden" name="payload" value="<?= Html::e($import['payload'] ?? '') ?>">
                <p>
                    <button type="submit" class="lf-button">Apply these changes</button>
                    <a href="<?= Html::e($cancelUrl) ?>" class="lf-button lf-button--secondary">Cancel</a>
                </p>
            </form>
        <?php else: ?>
            <p><a href="<?= Html::e($cancelUrl) ?>">Back to export and import</a></p>
        <?php endif; ?>
    </section>
<?php else: ?>
    <p class="lf-lead">Copy the way one site is set up to another. The file holds the settings that mean the same on any site: the archive, security, privacy, comments, downloads, feeds, news, front page, editor, retention and search engine settings, and the theme choice. It never holds the site address, the administrator's email, the secret key, the database details, the trusted proxy list or anything else that belongs to one install.</p>

    <section class="lf-card" aria-labelledby="lf-export-heading">
        <h2 id="lf-export-heading">Export</h2>
        <form method="post" action="<?= Html::e($exportAction) ?>" class="lf-form">
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
            <p class="lf-hint">Saves a small file you can keep as a record or bring to another site.</p>
            <p><button type="submit" class="lf-button">Download the settings file</button></p>
        </form>
    </section>

    <section class="lf-card" aria-labelledby="lf-import-heading">
        <h2 id="lf-import-heading">Import</h2>
        <form method="post" action="<?= Html::e($previewAction) ?>" enctype="multipart/form-data" class="lf-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
            <div class="lf-field<?= isset($errors['settings_file']) ? ' lf-field--error' : '' ?>">
                <label for="settings_file">Settings file</label>
                <input type="file" id="settings_file" name="settings_file" accept=".json,application/json" aria-describedby="settings_file_hint">
                <p class="lf-hint" id="settings_file_hint">A file saved by the export on a Lumora Fiction site, up to <?= (int) $maxKilobytes ?> KB. You see exactly what would change before anything is applied.</p>
            </div>
            <p><button type="submit" class="lf-button">Read the file</button></p>
        </form>
    </section>
<?php endif; ?>
