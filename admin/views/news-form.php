<?php

/**
 * Admin view: the news post form, a title, a pin switch, and the text editor with its Save and Publish buttons.
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
use LumoraFiction\Models\NewsPost;
use LumoraFiction\Services\NewsImageService;

/** @var NewsPost|null $post */
/** @var array<string, mixed>|null $imagePanel the picture panel's data, or null where pictures cannot be processed */
/** @var array{title: string, content: string, content_format: string, pinned: bool, image_alt: string, image_decorative: bool} $values */
/** @var string $editor trusted markup from the content editor */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $listUrl */
/** @var array<string, string> $urls */
/** @var int $titleMax */

$problem = static fn (string $name): string => isset($errors[$name]) ? '<p class="lf-error" id="' . Html::e($name) . '_error">' . Html::e($errors[$name]) . '</p>' : '';
?>
<?= $errorSummary ?>

<p><a href="<?= Html::e($listUrl) ?>">&larr; All news posts</a></p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form lf-form--wide" novalidate data-lf-guard<?= $imagePanel !== null ? ' enctype="multipart/form-data"' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-field<?= isset($errors['title']) ? ' lf-field--error' : '' ?>">
        <label for="title">Title</label>
        <input type="text" id="title" name="title" value="<?= Html::e($values['title']) ?>" maxlength="<?= (int) $titleMax ?>" aria-describedby="title_hint<?= isset($errors['title']) ? ' title_error' : '' ?>">
        <p class="lf-hint" id="title_hint">What readers see at the top of the post and in the list.</p>
        <?= $problem('title') ?>
    </div>

    <div class="lf-choice">
        <input type="checkbox" id="pinned" name="pinned" value="1"<?= $values['pinned'] ? ' checked' : '' ?> aria-describedby="pinned_hint">
        <label for="pinned">Pin to the top</label>
        <p class="lf-hint" id="pinned_hint">Pinned posts are listed before newer ones, on the home page and on the News page.</p>
    </div>

    <?php if ($imagePanel !== null): ?>
        <?php
        $current = $imagePanel['current'];
        $picker = $imagePanel['picker'];
        $backId = $post === null ? 'new' : (string) $post->id;
        ?>
        <section class="lf-card lf-news-image-field" aria-labelledby="image-heading">
            <h2 id="image-heading">Featured image</h2>
            <p class="lf-hint">One picture for the post, shown above the text, as a small picture in the lists and in the news feed. Optional.</p>

            <?php if ($current !== []): ?>
                <p class="lf-news-image-field__current"><img class="lf-news-image-field__preview" src="<?= Html::e((string) $current['thumb']) ?>" width="<?= (int) $current['thumb_width'] ?>" height="<?= (int) $current['thumb_height'] ?>" alt="<?= Html::e((string) $current['alt']) ?>"></p>
                <div class="lf-choice">
                    <input type="checkbox" id="image_remove" name="image_remove" value="1">
                    <label for="image_remove">Remove this picture from the post</label>
                </div>
            <?php endif; ?>

            <div class="lf-field<?= isset($errors['image']) ? ' lf-field--error' : '' ?>">
                <label for="image"><?= $current !== [] ? 'Replace the picture' : 'Upload a picture' ?></label>
                <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" aria-describedby="image_hint<?= isset($errors['image']) ? ' image_error' : '' ?>">
                <p class="lf-hint" id="image_hint">A JPEG, PNG or WebP picture, up to <?= Html::e($imagePanel['limit']) ?> MB. It is resized and saved again on this site; other details in the file, such as where a photo was taken, are not kept.</p>
                <?= $problem('image') ?>
            </div>

            <div class="lf-field<?= isset($errors['image_alt']) ? ' lf-field--error' : '' ?>">
                <label for="image_alt">Description of the picture</label>
                <input type="text" id="image_alt" name="image_alt" value="<?= Html::e($values['image_alt']) ?>" maxlength="<?= (int) NewsImageService::ALT_MAX ?>" aria-describedby="image_alt_hint<?= isset($errors['image_alt']) ? ' image_alt_error' : '' ?>">
                <p class="lf-hint" id="image_alt_hint">What the picture shows, in a short sentence, for readers who cannot see it. Say what matters for the post, not &ldquo;picture of&rdquo;. It belongs to this post only, even when the same picture is used in another.</p>
                <?= $problem('image_alt') ?>
            </div>

            <div class="lf-choice">
                <input type="checkbox" id="image_decorative" name="image_decorative" value="1"<?= $values['image_decorative'] ? ' checked' : '' ?> aria-describedby="image_decorative_hint">
                <label for="image_decorative">This picture is decoration</label>
                <p class="lf-hint" id="image_decorative_hint">Tick this when the picture adds no information, so screen readers skip it and no description is needed.</p>
            </div>

            <?php if ($picker['images'] !== []): ?>
                <fieldset class="lf-news-image-picker">
                    <legend>Or choose from your uploads</legend>
                    <?= $problem('image_choice') ?>
                    <p class="lf-hint">Newest first. Choosing a picture uses the same file, so it is stored once. An upload no post uses can be deleted here.</p>
                    <ul class="lf-news-image-picker__list">
                        <?php foreach ($picker['images'] as $entry): ?>
                            <?php $image = $entry['image']; ?>
                            <li class="lf-news-image-picker__item">
                                <input type="radio" id="image_choice_<?= (int) $image->id ?>" name="image_choice" value="<?= (int) $image->id ?>"<?= $post !== null && $post->imageId === $image->id ? ' checked' : '' ?>>
                                <label for="image_choice_<?= (int) $image->id ?>">
                                    <img src="<?= Html::e($imagePanel['thumbs'][$image->id] ?? '') ?>" width="<?= (int) $image->thumbWidth ?>" height="<?= (int) $image->thumbHeight ?>" alt="" loading="lazy">
                                    <span class="lf-news-image-picker__meta">Added <?= Html::e($image->createdAt->format('j M Y')) ?> &middot; used by <?= (int) $entry['uses'] ?> <?= $entry['uses'] === 1 ? 'post' : 'posts' ?></span>
                                </label>
                                <?php if ($entry['uses'] === 0): ?>
                                    <button type="submit" class="lf-button lf-button--quiet" formaction="<?= Html::e($imagePanel['deleteUrl'] . '/' . (int) $image->id . '/delete?post=' . $backId) ?>" formnovalidate aria-label="Delete the picture added <?= Html::e($image->createdAt->format('j M Y')) ?>">Delete this upload</button>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($picker['pages'] > 1): ?>
                        <nav class="lf-pagination" aria-label="Pages of your uploads">
                            <?php if ($picker['page'] > 1): ?><a href="<?= Html::e($imagePanel['pagerUrl'] . '?pictures=' . ($picker['page'] - 1)) ?>" rel="prev">Newer</a><?php endif; ?>
                            <span>Page <?= (int) $picker['page'] ?> of <?= (int) $picker['pages'] ?></span>
                            <?php if ($picker['page'] < $picker['pages']): ?><a href="<?= Html::e($imagePanel['pagerUrl'] . '?pictures=' . ($picker['page'] + 1)) ?>" rel="next">Older</a><?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </fieldset>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?= $problem('format') ?>
    <?= $problem('content') ?>

    <?= $editor ?>
</form>

<?php if ($post !== null): ?>
    <p>
        <a href="<?= Html::e($urls['preview']) ?>">Preview this post</a> &middot;
        <a href="<?= Html::e($urls['delete']) ?>">Delete this post</a>
    </p>
<?php endif; ?>
