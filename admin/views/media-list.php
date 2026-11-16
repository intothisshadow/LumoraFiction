<?php

/**
 * The Media screen: uploaded pictures with their uploader, size, dimensions and use, an upload form and bulk delete.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

use LumoraFiction\Core\Admin\BulkBar;
use LumoraFiction\Core\Media\ImageStore;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\NewsImage;

/** @var list<array{image: NewsImage, uses: int, owner: string}> $rows */
/** @var ImageStore $files */
/** @var array{owner?: int, unused?: bool} $filters */
/** @var list<array{id: int, name: string}> $uploaders */
/** @var array{count: int, bytes: int} $totals */
/** @var int $found */
/** @var int $page */
/** @var int $pages */
/** @var bool $available */
/** @var string $limit */
/** @var string $listUrl */
/** @var string $uploadUrl */
/** @var string $deleteUrl */
/** @var string $usedUrl */
/** @var string $csrfToken */
/** @var string $assetVersion */

$bulkForm = 'lf-bulk-form';
$size = static function (int $bytes): string {
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format(max(1, (int) round($bytes / 1024))) . ' KB';
};
$kept = [];

if (isset($filters['owner'])) {
    $kept['owner'] = (string) $filters['owner'];
}

if (($filters['unused'] ?? false) === true) {
    $kept['unused'] = '1';
}

$query = static function (int $number) use ($kept): string {
    $parts = $kept;

    if ($number > 1) {
        $parts['page'] = (string) $number;
    }

    return $parts === [] ? '' : '?' . http_build_query($parts);
};
?>
<p class="lf-hint"><?= (int) $totals['count'] ?> <?= $totals['count'] === 1 ? 'picture takes' : 'pictures take' ?> <?= Html::e($size($totals['bytes'])) ?> on this site. This screen lists the pictures uploaded for news posts; story covers, author pictures and theme pictures have their own screens.</p>

<?php if ($available): ?>
    <form method="post" action="<?= Html::e($uploadUrl) ?>" enctype="multipart/form-data" class="lf-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
        <section class="lf-card" aria-labelledby="upload-heading">
            <h2 id="upload-heading">Upload pictures</h2>
            <div class="lf-field">
                <label for="pictures">Pictures</label>
                <input type="file" id="pictures" name="pictures[]" multiple accept="image/jpeg,image/png,image/webp" aria-describedby="pictures_hint">
                <p class="lf-hint" id="pictures_hint">JPEG, PNG or WebP, up to <?= Html::e($limit) ?> MB each, checked and saved again on this site like any other picture. Choose several at once; one that is refused is named and does not stop the others. Pictures you upload here are owned by you, and every administrator can choose them from &ldquo;choose from your uploads&rdquo; in the news editor.</p>
            </div>
            <p><button type="submit" class="lf-button">Upload</button></p>
        </section>
    </form>
<?php else: ?>
    <p class="lf-notice lf-notice--warning" role="status">This server cannot process pictures, so pictures cannot be uploaded.</p>
<?php endif; ?>

<form method="get" action="<?= Html::e($listUrl) ?>" class="lf-form lf-filter" role="search">
    <div class="lf-field">
        <label for="owner">Uploaded by</label>
        <select id="owner" name="owner">
            <option value="">Anyone</option>
            <?php foreach ($uploaders as $uploader): ?>
                <option value="<?= (int) $uploader['id'] ?>"<?= ($filters['owner'] ?? 0) === $uploader['id'] ? ' selected' : '' ?>><?= Html::e($uploader['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="lf-choice">
        <input type="checkbox" id="unused" name="unused" value="1"<?= ($filters['unused'] ?? false) ? ' checked' : '' ?>>
        <label for="unused">Unused only</label>
    </div>
    <p><button type="submit" class="lf-button lf-button--secondary">Filter</button></p>
</form>

<?php if ($rows === []): ?>
    <p class="lf-hint"><?= $kept !== [] ? 'No picture matches.' : 'No pictures have been uploaded yet.' ?></p>
<?php else: ?>
    <p class="lf-hint"><?= (int) $found ?> found.</p>

    <?= BulkBar::form($bulkForm, $deleteUrl, $csrfToken, $kept + ['page' => $page > 1 ? (string) $page : '']) ?>
    <?= BulkBar::bar($bulkForm, 'pictures', ['delete' => 'Delete…']) ?>

    <table class="lf-table lf-table--selectable lf-media-table">
        <caption class="lf-visually-hidden">Uploaded pictures</caption>
        <thead>
            <tr>
                <?= BulkBar::selectAllHeader('picture_ids', $bulkForm, 'pictures') ?>
                <th scope="col">Picture</th>
                <th scope="col">File name</th>
                <th scope="col">Uploaded by</th>
                <th scope="col">Uploaded</th>
                <th scope="col">Size</th>
                <th scope="col">Dimensions</th>
                <th scope="col">Used by</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $image = $row['image']; ?>
                <tr>
                    <?= BulkBar::checkbox('picture_ids', $bulkForm, $image->id, 'Select the picture ' . $image->fileName) ?>
                    <td><img class="lf-media-table__thumb" src="<?= Html::e($files->url($image->fileName, true)) ?>" width="<?= (int) $image->thumbWidth ?>" height="<?= (int) $image->thumbHeight ?>" alt="" loading="lazy"></td>
                    <th scope="row"><?= Html::e($image->fileName) ?><br><a href="<?= Html::e($usedUrl . '/' . $image->id . '/delete') ?>">Delete&hellip;</a></th>
                    <td><?= $row['owner'] !== '' ? Html::e($row['owner']) : '&mdash;' ?></td>
                    <td><time datetime="<?= Html::e($image->createdAt->format('Y-m-d\TH:i:s')) ?>"><?= Html::e($image->createdAt->format('Y-m-d H:i')) ?></time></td>
                    <td><?= Html::e($size($image->sizeBytes)) ?></td>
                    <td><?= (int) $image->width ?> &times; <?= (int) $image->height ?></td>
                    <td><?php if ($row['uses'] > 0): ?><a href="<?= Html::e($usedUrl . '/' . $image->id) ?>"><?= (int) $row['uses'] ?> <?= $row['uses'] === 1 ? 'post' : 'posts' ?></a><?php else: ?>Unused<?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <nav class="lf-pagination" aria-label="Pages">
            <?php if ($page > 1): ?><a href="<?= Html::e($listUrl . $query($page - 1)) ?>" rel="prev">Previous</a><?php endif; ?>
            <span>Page <?= (int) $page ?> of <?= (int) $pages ?></span>
            <?php if ($page < $pages): ?><a href="<?= Html::e($listUrl . $query($page + 1)) ?>" rel="next">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>

    <?= BulkBar::script($assetVersion) ?>
<?php endif; ?>
