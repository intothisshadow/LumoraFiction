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
/** @var array<string, string> $splitModes */
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

<p class="lf-lead">Bring your own stories across from Archive of Our Own, Squidgeworld or any other site that runs the same archive software, or from any HTML file. A download keeps its fandoms, characters, relationships, tags, rating, warnings, summary, notes, chapters, dates, series and collections. Any other HTML file brings its title and text, and you fill in the rest in the preview. You see everything first, and nothing is saved until you confirm it.</p>

<section class="lf-card" aria-labelledby="import-how-heading">
    <h2 id="import-how-heading">How to get the file</h2>
    <ol>
        <li>Open your work on the other archive.</li>
        <li>Choose <strong>Download</strong> and then <strong>HTML</strong> or <strong>EPUB</strong>. Do this for each work you want to bring across.</li>
        <li>Upload the downloaded files here<?= $zipAvailable ? ' (or a ZIP file holding HTML files)' : '' ?>.</li>
    </ol>
    <p class="lf-hint">Nothing is fetched from the other site. Only the HTML and EPUB downloads are read; PDF and MOBI, and a work you cannot download, cannot be imported.</p>
</section>

<section class="lf-card" aria-labelledby="import-general-heading">
    <h2 id="import-general-heading">Any other HTML file</h2>
    <p>A page saved from another site, a file exported from Word or LibreOffice as HTML, or a hand-written file can be imported too. The title and text are read from the file, and its navigation, headers, footers, forms, scripts, styles and pictures are left out. A general file carries no rating, tags or author, so you choose those in the preview, and the story is always yours. Word, LibreOffice, PDF and other formats are not read directly: export them as HTML first.</p>
</section>

<form method="post" action="<?= Html::e($action) ?>" enctype="multipart/form-data" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-card">
        <div class="lf-field<?= isset($errors['work']) ? ' lf-field--error' : '' ?>">
            <label for="work">Files<?= $zipAvailable ? ' (HTML, EPUB or a ZIP of HTML files)' : ' (HTML or EPUB)' ?></label>
            <input type="file" id="work" name="work[]" multiple accept=".html,.htm,.epub<?= $zipAvailable ? ',.zip' : '' ?>" aria-describedby="work_hint">
            <p class="lf-hint" id="work_hint">Up to <?= (int) $maxFiles ?> files at once, each up to <?= (int) $maxMegabytes ?> MB.</p>
        </div>
        <fieldset class="lf-field">
            <legend>If a file is not a download from an archive</legend>
            <div class="lf-field">
                <label for="split">Cut a file into chapters</label>
                <select id="split" name="split">
                    <?php foreach ($splitModes as $value => $label): ?>
                        <option value="<?= Html::e($value) ?>"><?= Html::e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="lf-hint">You can change this for each story in the preview.</p>
            </div>
            <div class="lf-choice">
                <input type="radio" id="combine_one" name="combine" value="one" checked>
                <label for="combine_one">Several files are the chapters of one story, in the order given</label>
            </div>
            <div class="lf-choice">
                <input type="radio" id="combine_separate" name="combine" value="separate">
                <label for="combine_separate">Each file is a story of its own</label>
            </div>
            <div class="lf-choice">
                <input type="checkbox" id="force_general" name="force_general" value="1">
                <label for="force_general">Read every file as a plain HTML file, even a download from an archive</label>
            </div>
        </fieldset>
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
