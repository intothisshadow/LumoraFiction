<?php

/**
 * Asks before pictures are deleted, naming the news posts that still show them.
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

use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\NewsImage;
use LumoraFiction\Models\NewsPost;

/** @var list<array{image: NewsImage, thumb: string, posts: list<NewsPost>}> $items */
/** @var int $used how many of the pictures are still used by a post */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $backUrl */
/** @var string $editUrl */

$count = count($items);
?>
<form method="post" action="<?= Html::e($action) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
    <input type="hidden" name="confirmed" value="1">

    <div class="lf-card">
        <h2>Delete <?= $count === 1 ? 'this picture' : 'these ' . $count . ' pictures' ?>?</h2>
        <ul class="lf-media-delete">
            <?php foreach ($items as $item): ?>
                <li>
                    <input type="hidden" name="ids[]" value="<?= (int) $item['image']->id ?>">
                    <img src="<?= Html::e($item['thumb']) ?>" width="<?= (int) $item['image']->thumbWidth ?>" height="<?= (int) $item['image']->thumbHeight ?>" alt="">
                    <span><?= Html::e($item['image']->fileName) ?></span>
                    <?php if ($item['posts'] === []): ?>
                        <span class="lf-hint">No post uses it.</span>
                    <?php else: ?>
                        <span class="lf-hint">Used by:
                            <?php foreach ($item['posts'] as $index => $post): ?><?= $index > 0 ? ', ' : '' ?><a href="<?= Html::e($editUrl . '/' . $post->id) ?>"><?= Html::e($post->title) ?></a><?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($used > 0): ?>
            <div class="lf-choice">
                <input type="checkbox" id="remove_from_posts" name="remove_from_posts" value="1" aria-describedby="remove_from_posts_hint">
                <label for="remove_from_posts">Also remove <?= $used === 1 ? 'it' : 'them' ?> from the posts that use <?= $used === 1 ? 'it' : 'them' ?></label>
                <p class="lf-hint" id="remove_from_posts_hint">Those posts simply lose their picture and its description. Leave this unticked to keep the pictures that posts still show and delete only the others.</p>
            </div>
        <?php endif; ?>

        <p>This removes the files from the site and cannot be undone.</p>
        <p>
            <button type="submit" class="lf-button">Delete</button>
            <a href="<?= Html::e($backUrl) ?>">Cancel</a>
        </p>
    </div>
</form>
