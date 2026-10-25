<?php

/**
 * Admin view: the list of news posts, with search, a status filter, sortable columns and paging.
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
use LumoraFiction\Models\NewsPost;
use LumoraFiction\Models\PublicationState;

/** @var list<NewsPost> $posts */
/** @var ColumnSort $sort */
/** @var array{state: string, search: string} $filters */
/** @var list<PublicationState> $states */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var bool $enabled */
/** @var string $listUrl */
/** @var string $newUrl */
/** @var string $postUrl */
/** @var string $settingsUrl */

$kept = array_filter(['q' => $filters['search'], 'state' => $filters['state']], static fn (string $value): bool => $value !== '');
$query = static function (int $number) use ($kept, $sort): string {
    $parts = $kept + $sort->query();

    if ($number > 1) {
        $parts['page'] = (string) $number;
    }

    return $parts === [] ? '' : '?' . http_build_query($parts);
};

$filtered = $filters['search'] !== '' || $filters['state'] !== '';
?>
<?php if (!$enabled): ?>
    <p class="lf-notice lf-notice--warning" role="status">News is switched off, so readers cannot see any of these posts. <a href="<?= Html::e($settingsUrl) ?>">Change this in the settings.</a></p>
<?php endif; ?>

<p><a href="<?= Html::e($newUrl) ?>" class="lf-button">Add a news post</a></p>

<form method="get" action="<?= Html::e($listUrl) ?>" class="lf-form lf-filter" role="search">
    <div class="lf-field">
        <label for="q">Search titles</label>
        <input type="text" id="q" name="q" value="<?= Html::e($filters['search']) ?>" maxlength="100">
    </div>
    <div class="lf-field">
        <label for="state">Status</label>
        <select id="state" name="state">
            <option value="">Any status</option>
            <?php foreach ($states as $state): ?>
                <option value="<?= Html::e($state->value) ?>"<?= $filters['state'] === $state->value ? ' selected' : '' ?>><?= Html::e($state->label()) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php foreach ($sort->query() as $name => $value): ?>
        <input type="hidden" name="<?= Html::e($name) ?>" value="<?= Html::e($value) ?>">
    <?php endforeach; ?>
    <p><button type="submit" class="lf-button lf-button--secondary">Filter</button></p>
</form>

<?php if ($posts === []): ?>
    <p class="lf-hint"><?= $filtered ? 'No news post matches.' : 'There are no news posts yet. Add one to tell readers what is happening.' ?></p>
<?php else: ?>
    <p class="lf-hint"><?= (int) $total ?> found.</p>

    <table class="lf-table">
        <caption class="lf-visually-hidden">News posts</caption>
        <thead>
            <tr>
                <?= SortHeading::render($sort, 'title', 'Title', $listUrl, $kept) ?>
                <?= SortHeading::render($sort, 'status', 'Status', $listUrl, $kept) ?>
                <?= SortHeading::render($sort, 'published', 'Published', $listUrl, $kept) ?>
                <?= SortHeading::render($sort, 'updated', 'Last changed', $listUrl, $kept) ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($posts as $post): ?>
                <tr>
                    <th scope="row">
                        <a href="<?= Html::e($postUrl . '/' . $post->id) ?>"><?= Html::e($post->title) ?></a>
                        <?php if ($post->pinned): ?><span class="lf-status">Pinned</span><?php endif; ?>
                    </th>
                    <td><span class="lf-status<?= $post->isPublished() ? ' lf-status--ok' : '' ?>"><?= Html::e($post->state->label()) ?></span></td>
                    <td><?php if ($post->publishedAt !== null): ?><time datetime="<?= Html::e($post->publishedAt->format('Y-m-d\TH:i:s')) ?>"><?= Html::e($post->publishedAt->format('Y-m-d H:i')) ?></time><?php else: ?>&mdash;<?php endif; ?></td>
                    <td><time datetime="<?= Html::e($post->updatedAt->format('Y-m-d\TH:i:s')) ?>"><?= Html::e($post->updatedAt->format('Y-m-d H:i')) ?></time></td>
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
