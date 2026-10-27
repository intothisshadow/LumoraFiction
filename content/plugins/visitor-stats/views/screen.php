<?php

/**
 * Visitor stats screen.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;

/** @var int $range */
/** @var list<int> $ranges */
/** @var Closure(int): string $rangeUrl */
/** @var list<array{date: string, views: int}> $rows */
/** @var bool $weekly */
/** @var int $peak */
/** @var array{today: int, week: int, month: int, all: int} $totals */
/** @var list<array{story_id: int, views: int, title: string, url: string}> $stories */
/** @var list<array{value: string, views: int}> $referrers */
/** @var list<array{value: string, views: int}> $browsers */
/** @var list<array{value: string, views: int}> $devices */
/** @var list<array{value: string, views: int}> $countries */
/** @var bool $tracking */
/** @var array{ranges: int, importedAt: int|null, stale: bool, pending: array{offset: int, imported: int, started: int}|null, incoming: list<array{id: string, label: string}>, incomingFolder: string, autoAdvance: bool} $geo */
/** @var string $systemInformationUrl */
/** @var string $scriptUrl */
/** @var string $token */
/** @var array<string, string> $actions */

$lists = [
    ['Top referrers', 'Websites that sent readers here', $referrers, 'Nothing yet. Only other sites are listed, and only their domain.'],
    ['Browsers', 'What readers use', $browsers, 'Nothing yet.'],
    ['Devices', 'Phone, tablet or computer', $devices, 'Nothing yet.'],
    ['Countries', 'Where readers are', $countries, $geo['ranges'] === 0 ? 'Load the country data below to count countries.' : 'Nothing yet.'],
];
?>
<p class="lf-lead">Reading figures for stories, counted on this site alone. Nothing identifies a reader: only totals for each day are kept, with no cookies, no address, no browser string and no full web address of where a reader came from.</p>

<form method="post" action="<?= Html::e($actions['settings']) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($token) ?>">

    <fieldset class="lf-card">
        <legend>Counting</legend>
        <div class="lf-choice">
            <input type="checkbox" id="track" name="track" value="1"<?= $tracking ? ' checked' : '' ?> aria-describedby="track_hint">
            <label for="track">Count views</label>
        </div>
        <p class="lf-hint" id="track_hint">One view is counted each time someone who is not signed in opens a story or chapter. Signed-in people, previews, drafts, stories waiting for approval and search engine robots are never counted. The plugin counts nothing until this is on, and the figures so far are kept if you turn it off.</p>
    </fieldset>

    <p><button type="submit" class="lf-button">Save</button></p>
</form>

<section class="lf-card" aria-labelledby="vs-over-time">
    <h2 id="vs-over-time">Views over time</h2>
    <p>
        <?php foreach ($ranges as $days): ?>
            <?php if ($days === $range): ?>
                <strong aria-current="true">Last <?= (int) $days ?> days</strong>
            <?php else: ?>
                <a href="<?= Html::e($rangeUrl($days)) ?>">Last <?= (int) $days ?> days</a>
            <?php endif; ?>
            <?= $days === end($ranges) ? '' : '&middot;' ?>
        <?php endforeach; ?>
    </p>
    <p>Today <?= number_format($totals['today']) ?> &middot; last 7 days <?= number_format($totals['week']) ?> &middot; last 30 days <?= number_format($totals['month']) ?> &middot; all time <?= number_format($totals['all']) ?></p>
    <table class="lf-table">
        <caption class="lf-visually-hidden">Views for each <?= $weekly ? 'week' : 'day' ?></caption>
        <thead><tr><th scope="col"><?= $weekly ? 'Week' : 'Day' ?></th><th scope="col">Views</th><th scope="col"><span class="lf-visually-hidden">Compared with the busiest <?= $weekly ? 'week' : 'day' ?></span></th></tr></thead>
        <tbody>
            <?php foreach (array_reverse($rows) as $row): ?>
                <tr>
                    <th scope="row"><?= Html::e($row['date']) ?></th>
                    <td><?= number_format($row['views']) ?></td>
                    <td><meter min="0" max="<?= (int) $peak ?>" value="<?= (int) $row['views'] ?>"><?= number_format($row['views']) ?></meter></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="lf-card" aria-labelledby="vs-top-stories">
    <h2 id="vs-top-stories">Most read stories, last <?= (int) $range ?> days</h2>
    <?php if ($stories === []): ?>
        <p>No story has been read in this time.</p>
    <?php else: ?>
        <table class="lf-table">
            <thead><tr><th scope="col">Story</th><th scope="col">Views</th></tr></thead>
            <tbody>
                <?php foreach ($stories as $story): ?>
                    <tr><th scope="row"><a href="<?= Html::e($story['url']) ?>"><?= Html::e($story['title']) ?></a></th><td><?= number_format($story['views']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<?php foreach ($lists as $index => [$title, $caption, $items, $empty]): ?>
    <section class="lf-card" aria-labelledby="vs-list-<?= (int) $index ?>">
        <h2 id="vs-list-<?= (int) $index ?>"><?= Html::e($title) ?>, last <?= (int) $range ?> days</h2>
        <?php if ($items === []): ?>
            <p><?= Html::e($empty) ?></p>
        <?php else: ?>
            <table class="lf-table">
                <caption class="lf-visually-hidden"><?= Html::e($caption) ?></caption>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr><th scope="row"><?= Html::e($item['value']) ?></th><td><?= number_format($item['views']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<section class="lf-card" aria-labelledby="vs-country-data">
    <h2 id="vs-country-data">Country data</h2>
    <?php if ($geo['pending'] !== null): ?>
        <p>An import is under way. The country data already loaded stays in use until the new data is finished, so counting is not interrupted.<?= $geo['autoAdvance'] ? ' This page carries on by itself; please keep it open.' : ' Press Continue to carry on; each press reads a few tens of thousands of rows.' ?></p>
        <div aria-live="polite">
            <p data-lf-run-message>Read <?= number_format($geo['pending']['imported']) ?> ranges so far.</p>
            <p class="lf-update-working" data-lf-run-status hidden></p>
        </div>
        <div class="lf-inline-actions">
            <form method="post" action="<?= Html::e($actions['continue']) ?>" class="lf-inline-form" data-lf-autoadvance="<?= $geo['autoAdvance'] ? '1' : '0' ?>" data-lf-status="running">
                <input type="hidden" name="csrf_token" value="<?= Html::e($token) ?>">
                <button type="submit" class="lf-button">Continue</button>
            </form>
            <form method="post" action="<?= Html::e($actions['cancel']) ?>" class="lf-inline-form">
                <input type="hidden" name="csrf_token" value="<?= Html::e($token) ?>">
                <button type="submit" class="lf-button lf-button--secondary">Cancel the import</button>
            </form>
        </div>
        <?php if ($geo['autoAdvance']): ?>
            <script src="<?= Html::e($scriptUrl) ?>" defer></script>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($geo['ranges'] > 0): ?>
            <p><?= number_format($geo['ranges']) ?> address ranges are loaded<?= $geo['importedAt'] === null ? '' : ', imported on ' . Html::e(date('Y-m-d', $geo['importedAt'])) ?>. Only IPv4 addresses are matched.</p>
            <?php if ($geo['stale']): ?>
                <div class="lf-notice lf-notice--warning" role="status"><p>This data is more than six months old. MaxMind updates it regularly, so download a fresh copy and import it again.</p></div>
            <?php endif; ?>
        <?php else: ?>
            <p>No country data is loaded, so countries are not counted. Nothing is bundled and this plugin never downloads anything: you bring the file yourself.</p>
        <?php endif; ?>

        <p class="lf-hint">Get the free <em>GeoLite2 Country</em> database from MaxMind in CSV format (you need a free MaxMind account). The data is kept in a file under <code>storage/visitor-stats/</code>, never in the database. A browser upload often fails on a large file, so the most reliable way is to put the file on the server yourself (by FTP or your host's file manager) and import it from there.</p>

        <h3>From a file already on the server</h3>
        <p class="lf-hint">Put the ZIP (its name starts with <code>GeoLite2</code>), or the two files <code>GeoLite2-Country-Blocks-IPv4.csv</code> and <code>GeoLite2-Country-Locations-en.csv</code>, in the folder <code><?= Html::e($geo['incomingFolder']) ?></code> inside your site's folder (<a href="<?= Html::e($systemInformationUrl) ?>">System Information</a> shows where that is), then reload this page.</p>
        <?php if ($geo['incoming'] === []): ?>
            <p>Nothing is waiting in that folder.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($geo['incoming'] as $file): ?>
                    <li>
                        <form method="post" action="<?= Html::e($actions['incoming']) ?>" class="lf-inline-form">
                            <input type="hidden" name="csrf_token" value="<?= Html::e($token) ?>">
                            <input type="hidden" name="incoming" value="<?= Html::e($file['id']) ?>">
                            <?= Html::e($file['label']) ?>
                            <button type="submit" class="lf-button lf-button--secondary">Import</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="post" action="<?= Html::e($actions['path']) ?>" class="lf-form">
            <input type="hidden" name="csrf_token" value="<?= Html::e($token) ?>">
            <div class="lf-field">
                <label for="geo_path">Or the full path of a file or folder elsewhere on the server</label>
                <input type="text" id="geo_path" name="path" autocomplete="off" spellcheck="false" aria-describedby="geo_path_hint">
                <p class="lf-hint" id="geo_path_hint">A <code>.zip</code> file, or a folder holding the two CSV files, for example <code>/home/you/GeoLite2-Country-CSV.zip</code>. A shortcut (symbolic link) is refused, and so is anything PHP is not allowed to read.</p>
            </div>
            <p><button type="submit" class="lf-button lf-button--secondary">Import from this path</button></p>
        </form>

        <h3>Or upload it here</h3>
        <form method="post" action="<?= Html::e($actions['upload']) ?>" enctype="multipart/form-data" class="lf-form">
            <input type="hidden" name="csrf_token" value="<?= Html::e($token) ?>">
            <p class="lf-hint">Choose either the whole ZIP, or the two CSV files. This works for small files; if it fails with a size error, use one of the ways above.</p>
            <div class="lf-field">
                <label for="geo_zip">ZIP file</label>
                <input type="file" id="geo_zip" name="geo_zip" accept=".zip">
            </div>
            <div class="lf-field">
                <label for="geo_blocks">Or the IPv4 blocks file</label>
                <input type="file" id="geo_blocks" name="geo_blocks" accept=".csv">
            </div>
            <div class="lf-field">
                <label for="geo_locations">and the locations file</label>
                <input type="file" id="geo_locations" name="geo_locations" accept=".csv">
            </div>
            <p><button type="submit" class="lf-button lf-button--secondary"><?= $geo['ranges'] > 0 ? 'Replace the country data' : 'Upload and start the import' ?></button></p>
        </form>

        <?php if ($geo['ranges'] > 0): ?>
            <form method="post" action="<?= Html::e($actions['remove']) ?>" class="lf-inline-form">
                <input type="hidden" name="csrf_token" value="<?= Html::e($token) ?>">
                <button type="submit" class="lf-button lf-button--secondary">Remove the country data</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
