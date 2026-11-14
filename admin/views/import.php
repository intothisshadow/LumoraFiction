<?php

/**
 * Admin: import a story from another archive (step one, choosing the files).
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

/** @var array<string, string> $errors */
/** @var string $pseuds */
/** @var bool $zipAvailable */
/** @var int $maxFiles */
/** @var int $maxMegabytes */
/** @var list<array{token: string, state: string, createdAt: DateTimeImmutable, imported: int, undone: bool}> $runs */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $reportBase */
/** @var string $runBase */
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

<p class="lf-lead">Bring your own stories across from Archive of Our Own, Squidgeworld or any other site that runs the same archive software. You keep the fandoms, characters, relationships, tags, rating, warnings, summary, notes, chapters, dates, series and collections. You see everything in a preview first, and nothing is saved until you confirm it.</p>

<section class="lf-card" aria-labelledby="import-how-heading">
    <h2 id="import-how-heading">How to get the file</h2>
    <ol>
        <li>Open your work on the other archive.</li>
        <li>Choose <strong>Download</strong> and then <strong>HTML</strong> or <strong>EPUB</strong>. Do this for each work you want to bring across.</li>
        <li>Upload the downloaded files here<?= $zipAvailable ? ' (or a ZIP file holding HTML files)' : '' ?>.</li>
    </ol>
    <p class="lf-hint">Nothing is fetched from the other site. Only the HTML and EPUB downloads are read; PDF and MOBI, and a work you cannot download, cannot be imported.</p>
</section>

<form method="post" action="<?= Html::e($action) ?>" enctype="multipart/form-data" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-card">
        <div class="lf-field<?= isset($errors['work']) ? ' lf-field--error' : '' ?>">
            <label for="work">Downloaded file<?= $zipAvailable ? 's (HTML, EPUB or a ZIP of HTML files)' : 's (HTML or EPUB)' ?></label>
            <input type="file" id="work" name="work[]" multiple accept=".html,.htm,.epub<?= $zipAvailable ? ',.zip' : '' ?>" aria-describedby="work_hint">
            <p class="lf-hint" id="work_hint">Up to <?= (int) $maxFiles ?> files at once, each up to <?= (int) $maxMegabytes ?> MB.</p>
        </div>
        <div class="lf-field">
            <label for="pseuds">Other names you write under (optional)</label>
            <input type="text" id="pseuds" name="pseuds" value="<?= Html::e($pseuds) ?>" maxlength="300" aria-describedby="pseuds_hint">
            <p class="lf-hint" id="pseuds_hint">Separate names with commas. Used only to warn you when a file seems to be by someone else.</p>
        </div>
        <p><button type="submit" class="lf-button">Read the files</button></p>
    </div>
</form>

<?php if ($runs !== []): ?>
    <section class="lf-card" aria-labelledby="import-runs-heading">
        <h2 id="import-runs-heading">Earlier imports</h2>
        <table class="lf-table">
            <caption class="lf-visually-hidden">Earlier imports</caption>
            <thead>
                <tr>
                    <th scope="col">When</th>
                    <th scope="col">Stories</th>
                    <th scope="col">Report</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($runs as $run): ?>
                    <tr>
                        <th scope="row"><time datetime="<?= Html::e($run['createdAt']->format('Y-m-d\TH:i:s')) ?>"><?= Html::e($run['createdAt']->format('Y-m-d H:i')) ?></time></th>
                        <td><?= (int) $run['imported'] ?><?= $run['undone'] ? ' (undone)' : '' ?><?= $run['state'] === 'running' ? ' (not finished)' : '' ?><?= $run['state'] === 'cancelled' ? ' (stopped)' : '' ?></td>
                        <td>
                            <?php if ($run['state'] === 'running'): ?>
                                <a href="<?= Html::e($runBase . '/' . $run['token']) ?>">Carry on with this import</a>
                            <?php else: ?>
                                <a href="<?= Html::e($reportBase . '/' . $run['token']) ?>">Open the report</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>
