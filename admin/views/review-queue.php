<?php

/**
 * The approval queue: stories waiting for review.
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

use LumoraFiction\Core\Admin\ColumnSort;
use LumoraFiction\Core\Admin\SortHeading;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\Story;

/** @var list<Story> $stories */
/** @var ColumnSort $sort */
/** @var array<int, string> $owners display names by account id */
/** @var array<int, array{total: int, published: int}> $chapterCounts */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var string $listUrl */
/** @var string $storyUrl */
/** @var string $reviewUrl */
/** @var string $csrfToken */

$query = static fn (int $number): string => ($parts = $sort->query() + ($number > 1 ? ['page' => (string) $number] : [])) === [] ? '' : '?' . http_build_query($parts);
?>
<?php if ($stories === []): ?>
    <p class="lf-hint">No story is waiting for approval.</p>
<?php else: ?>
    <p class="lf-hint"><?= (int) $total ?> waiting<?= $sort->isDefault ? ', the one waiting longest first' : '' ?>.</p>

    <table class="lf-table lf-review-queue">
        <caption class="lf-visually-hidden">Stories waiting for approval</caption>
        <thead>
            <tr>
                <?= SortHeading::render($sort, 'title', 'Title', $listUrl, []) ?>
                <?= SortHeading::render($sort, 'author', 'Author', $listUrl, []) ?>
                <?= SortHeading::render($sort, 'chapters', 'Chapters', $listUrl, []) ?>
                <?= SortHeading::render($sort, 'words', 'Words', $listUrl, []) ?>
                <?= SortHeading::render($sort, 'sent', 'Sent', $listUrl, []) ?>
                <th scope="col">Decision</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($stories as $story): ?>
                <?php $counts = $chapterCounts[$story->id] ?? ['total' => 0, 'published' => 0]; ?>
                <tr>
                    <th scope="row"><a href="<?= Html::e($storyUrl . '/' . $story->id . '/preview') ?>"><?= Html::e($story->title) ?></a></th>
                    <td><?= Html::e($owners[$story->ownerId] ?? 'Unknown') ?></td>
                    <td><?= (int) $counts['total'] ?></td>
                    <td><?= number_format($story->wordCount) ?></td>
                    <td><time datetime="<?= Html::e($story->updatedAt->format('Y-m-d\TH:i:s')) ?>"><?= Html::e($story->updatedAt->format('Y-m-d H:i')) ?></time></td>
                    <td class="lf-review-queue__actions">
                        <form method="post" action="<?= Html::e($reviewUrl . '/' . $story->id . '/approve') ?>" class="lf-inline-form">
                            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                            <button type="submit" class="lf-button">Approve<span class="lf-visually-hidden"> <?= Html::e($story->title) ?></span></button>
                        </form>
                        <a href="<?= Html::e($reviewUrl . '/' . $story->id . '/reject') ?>" class="lf-button lf-button--secondary">Send back<span class="lf-visually-hidden"> <?= Html::e($story->title) ?></span></a>
                    </td>
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
<?php endif; ?>
