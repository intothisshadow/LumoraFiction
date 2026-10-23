<?php

/**
 * Admin view: stories and chapters in the trash, with restore and delete-for-good buttons.
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
use LumoraFiction\Models\Story;

/** @var list<Story> $stories */
/** @var list<array{chapter: LumoraFiction\Models\Chapter, story_id: int, story_title: string, story_trashed: bool}> $chapters */
/** @var array<int, string> $owners display names by account id; empty unless everyone's items are shown */
/** @var bool $all */
/** @var bool $canSeeAll */
/** @var int $days */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $listUrl */

$empty = $stories === [] && $chapters === [];
?>
<p class="lf-lead">
    Stories and chapters you move to the trash wait here, hidden from readers.
    <?= $days > 0 ? 'Each is deleted for good ' . (int) $days . ' days after it was trashed.' : 'They stay until you delete them.' ?>
    A restored item comes back as a draft or unpublished, never straight to readers.
</p>

<?php if ($canSeeAll): ?>
    <p>
        <?php if ($all): ?>Showing everyone&rsquo;s. <a href="<?= Html::e($listUrl) ?>">Show only mine</a><?php else: ?>Showing only yours. <a href="<?= Html::e($listUrl . '?scope=all') ?>">Show everyone&rsquo;s</a><?php endif; ?>
    </p>
<?php endif; ?>

<?php if ($empty): ?>
    <p class="lf-hint">The trash is empty.</p>
<?php else: ?>
    <form method="post" action="<?= Html::e($action) ?>" class="lf-form">
        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

        <?php if ($stories !== []): ?>
            <table class="lf-table lf-table--selectable lf-trash-list">
                <caption>Stories</caption>
                <thead>
                    <tr>
                        <th scope="col"><span class="lf-visually-hidden">Select</span></th>
                        <th scope="col">Title</th>
                        <?php if ($all): ?><th scope="col">Author</th><?php endif; ?>
                        <th scope="col">Trashed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stories as $story): ?>
                        <tr>
                            <td><input type="checkbox" name="stories[]" value="<?= (int) $story->id ?>" id="story_<?= (int) $story->id ?>" aria-label="Select <?= Html::e($story->title) ?>"></td>
                            <th scope="row"><?= Html::e($story->title) ?></th>
                            <?php if ($all): ?><td><?= Html::e($owners[$story->ownerId] ?? 'Unknown') ?></td><?php endif; ?>
                            <td><?= $story->trashedAt === null ? '' : '<time datetime="' . Html::e($story->trashedAt->format('Y-m-d\TH:i:s')) . '">' . Html::e($story->trashedAt->format('Y-m-d H:i')) . '</time>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($chapters !== []): ?>
            <table class="lf-table lf-table--selectable lf-trash-list">
                <caption>Chapters</caption>
                <thead>
                    <tr>
                        <th scope="col"><span class="lf-visually-hidden">Select</span></th>
                        <th scope="col">Chapter</th>
                        <th scope="col">Story</th>
                        <th scope="col">Trashed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($chapters as $row): ?>
                        <?php $name = $row['chapter']->title !== '' ? $row['chapter']->title : 'Untitled chapter'; ?>
                        <tr>
                            <td><input type="checkbox" name="chapters[]" value="<?= (int) $row['chapter']->id ?>" id="chapter_<?= (int) $row['chapter']->id ?>" aria-label="Select <?= Html::e($name) ?>"></td>
                            <th scope="row"><?= Html::e($name) ?></th>
                            <td><?= Html::e($row['story_title']) ?><?= $row['story_trashed'] ? ' <span class="lf-status lf-status--warning">in the trash</span>' : '' ?></td>
                            <td><?= $row['chapter']->trashedAt === null ? '' : '<time datetime="' . Html::e($row['chapter']->trashedAt->format('Y-m-d\TH:i:s')) . '">' . Html::e($row['chapter']->trashedAt->format('Y-m-d H:i')) . '</time>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="lf-bulk">
            <label for="trash_action">With the ticked items</label>
            <select id="trash_action" name="trash_action">
                <option value="">Choose&hellip;</option>
                <option value="restore">Restore</option>
                <option value="delete">Delete for good&hellip;</option>
                <option value="empty">Delete everything in the trash&hellip;</option>
            </select>
            <button type="submit" class="lf-button lf-button--secondary">Apply</button>
        </div>
    </form>
<?php endif; ?>
