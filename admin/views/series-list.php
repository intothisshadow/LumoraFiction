<?php

/**
 * Admin: the list of series.
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
use LumoraFiction\Models\Series;

/** @var list<array{series: Series, stories: int, owner: string}> $rows */
/** @var bool $all */
/** @var bool $mayListAll */
/** @var string $newUrl */
/** @var string $baseUrl */
?>
<p class="lf-lead">A series is a sequence of your own stories in a fixed order, such as a sequel and the story before it. Readers see &ldquo;Part 2 of 3&rdquo; on each story and a page for the whole series. A story can be in one series.</p>

<p>
    <a class="lf-button" href="<?= Html::e($newUrl) ?>">Add a series</a>
    <?php if ($mayListAll): ?>
        <?php if ($all): ?>
            <a href="<?= Html::e($baseUrl) ?>">Show only my series</a>
        <?php else: ?>
            <a href="<?= Html::e($baseUrl . '?scope=all') ?>">Show every author&rsquo;s series</a>
        <?php endif; ?>
    <?php endif; ?>
</p>

<?php if ($rows === []): ?>
    <p class="lf-hint">There are no series yet.</p>
<?php else: ?>
    <table class="lf-table">
        <caption class="lf-visually-hidden">Series</caption>
        <thead>
            <tr>
                <th scope="col">Title</th>
                <?php if ($all): ?><th scope="col">Author</th><?php endif; ?>
                <th scope="col">Stories</th>
                <th scope="col">Status</th>
                <th scope="col">Last changed</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $series = $row['series']; ?>
                <tr>
                    <th scope="row"><a href="<?= Html::e($baseUrl . '/' . $series->id) ?>"><?= Html::e($series->title) ?></a></th>
                    <?php if ($all): ?><td><?= Html::e($row['owner']) ?></td><?php endif; ?>
                    <td><?= (int) $row['stories'] ?></td>
                    <td><?= Html::e($series->workStatus->label()) ?></td>
                    <td><time datetime="<?= Html::e($series->updatedAt->format('Y-m-d\TH:i:s')) ?>"><?= Html::e($series->updatedAt->format('Y-m-d H:i')) ?></time></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
