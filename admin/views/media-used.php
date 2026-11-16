<?php

/**
 * The news posts that use one picture, each linked to its editor.
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

/** @var NewsImage $image */
/** @var string $thumb */
/** @var list<NewsPost> $posts */
/** @var string $listUrl */
/** @var string $editUrl */
?>
<p><a href="<?= Html::e($listUrl) ?>">&larr; All pictures</a></p>

<div class="lf-card">
    <h2><?= Html::e($image->fileName) ?></h2>
    <p><img class="lf-news-image-field__preview" src="<?= Html::e($thumb) ?>" width="<?= (int) $image->thumbWidth ?>" height="<?= (int) $image->thumbHeight ?>" alt=""></p>
    <?php if ($posts === []): ?>
        <p>No news post uses this picture.</p>
    <?php else: ?>
        <p>Used by <?= count($posts) ?> <?= count($posts) === 1 ? 'news post' : 'news posts' ?>. The description of the picture belongs to each post and is changed in that post's editor.</p>
        <ul>
            <?php foreach ($posts as $post): ?>
                <li><a href="<?= Html::e($editUrl . '/' . $post->id) ?>"><?= Html::e($post->title) ?></a> <span class="lf-hint">(<?= Html::e($post->state->label()) ?>)</span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
