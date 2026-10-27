<?php

/**
 * The author profile screen: the text and the picture shown on the writer's author page.
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

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Helpers\Html;

/** @var string $editor trusted markup from the content editor */
/** @var array<string, string> $errors */
/** @var string $csrfToken */
/** @var string $aboutAction */
/** @var string $pictureAction */
/** @var string $avatar the address of the current picture, or an empty string */
/** @var bool $avatarAvailable whether this server can process pictures */
/** @var string $avatarLimit the largest upload in megabytes */
/** @var int $aboutLimit */
/** @var string $authorUrl */
/** @var string $downloadsAction the address the downloads default is saved to, empty when downloads cannot be configured */
/** @var bool $downloadsDefault whether the author's default allows downloads */
/** @var bool $downloadsSiteOn whether downloads are switched on for the whole site */
/** @var string $assetVersion */

$problem = static fn (string $name): string => isset($errors[$name]) ? '<p class="lf-error" id="' . Html::e($name) . '_error" role="alert">' . Html::e($errors[$name]) . '</p>' : '';
?>
<?php if (isset($errors['form'])): ?>
    <p class="lf-notice lf-notice--error" role="alert"><?= Html::e($errors['form']) ?></p>
<?php endif; ?>

<p class="lf-lead">What you write here is shown on <a href="<?= Html::e($authorUrl) ?>">your author page</a>, above your list of stories. Everything here is public. The page itself appears once you have a published story.</p>

<section class="lf-card" aria-labelledby="about-heading">
    <h2 id="about-heading">About you</h2>
    <p class="lf-hint">Optional. Say what you like about yourself and your writing, up to <?= number_format($aboutLimit) ?> characters. You can format it as you would a chapter. Leave it empty to show nothing.</p>
    <?= $problem('about') ?>

    <form method="post" action="<?= Html::e($aboutAction) ?>" class="lf-form lf-form--wide" novalidate data-lf-guard>
        <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
        <?= $editor ?>
    </form>
</section>

<?php if ($avatarAvailable): ?>
    <section class="lf-card" aria-labelledby="picture-heading">
        <h2 id="picture-heading">Picture</h2>
        <p class="lf-hint">Optional. Shown beside your author information as a small square, 100 by 100 pixels.</p>

        <form method="post" action="<?= Html::e($pictureAction) ?>" class="lf-form" enctype="multipart/form-data" novalidate data-lf-guard>
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

            <div class="lf-cover-field">
                <?php if ($avatar !== ''): ?>
                    <p class="lf-cover-field__current">
                        <img class="lf-avatar-field__image" src="<?= Html::e($avatar) ?>" width="100" height="100" alt="">
                    </p>
                <?php endif; ?>

                <div class="lf-cover-field__controls">
                    <div class="lf-field<?= isset($errors['avatar']) ? ' lf-field--error' : '' ?>">
                        <label for="avatar"><?= $avatar !== '' ? 'Replace your picture' : 'Your picture' ?></label>
                        <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp" aria-describedby="avatar_hint<?= isset($errors['avatar']) ? ' avatar_error' : '' ?>">
                        <p class="lf-hint" id="avatar_hint">A JPEG, PNG or WebP picture, at least 100 pixels wide and tall, up to <?= Html::e($avatarLimit) ?> MB. It is resized and saved again on this site; other details in the file, such as where a photo was taken, are not kept.</p>
                        <?= $problem('avatar') ?>
                    </div>

                    <?php // Shown by the script once a picture is chosen; without a script the middle of the picture is used. ?>
                    <div class="lf-cropper" data-lf-cropper data-lf-input="avatar" data-lf-ratio="1" hidden>
                        <p class="lf-hint" id="avatar_crop_hint">The picture is shown as a square. Drag the frame over the picture, or use the sliders, to choose the part to keep.</p>
                        <canvas class="lf-cropper__canvas" width="480" height="320" role="img" aria-label="Preview of the picture with the frame"></canvas>
                        <div class="lf-cropper__controls">
                            <div class="lf-choice">
                                <input type="checkbox" id="avatar_crop_use" data-lf-crop-use>
                                <label for="avatar_crop_use">Crop the picture to this frame</label>
                            </div>
                            <div class="lf-field"><label for="avatar_crop_zoom">Zoom</label><input type="range" id="avatar_crop_zoom" min="100" max="400" value="100" step="1" data-lf-crop-zoom></div>
                            <div class="lf-field"><label for="avatar_crop_left">Left to right</label><input type="range" id="avatar_crop_left" min="0" max="100" value="50" step="1" data-lf-crop-x></div>
                            <div class="lf-field"><label for="avatar_crop_top">Top to bottom</label><input type="range" id="avatar_crop_top" min="0" max="100" value="50" step="1" data-lf-crop-y></div>
                        </div>
                        <input type="hidden" name="crop_x" value="">
                        <input type="hidden" name="crop_y" value="">
                        <input type="hidden" name="crop_w" value="">
                        <input type="hidden" name="crop_h" value="">
                    </div>

                    <?php if ($avatar !== ''): ?>
                        <div class="lf-choice">
                            <input type="checkbox" id="avatar_remove" name="avatar_remove" value="1">
                            <label for="avatar_remove">Remove my picture</label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <p><button type="submit" class="lf-button">Save picture</button></p>
        </form>
    </section>

    <script src="<?= Html::e(AdminUrl::asset('js/cover-crop.js', $assetVersion ?? '')) ?>" defer></script>
<?php endif; ?>

<?php if ($downloadsAction !== ''): ?>
    <section class="lf-card" aria-labelledby="downloads-heading">
        <h2 id="downloads-heading">Downloads</h2>
        <form method="post" action="<?= Html::e($downloadsAction) ?>" class="lf-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
            <div class="lf-choice">
                <input type="checkbox" id="downloads_default" name="downloads_default" value="1"<?= $downloadsDefault ? ' checked' : '' ?> aria-describedby="downloads_default_hint">
                <label for="downloads_default">Let readers download my stories</label>
            </div>
            <p class="lf-hint" id="downloads_default_hint">This is the default for all your stories, including ones you publish later. Readers can take a story away as a file in the formats the site offers. You can still allow or refuse downloads for one story on its own screen, and that choice wins over this one.<?= $downloadsSiteOn ? '' : ' Downloads are switched off for the whole site at the moment, so this has no effect until an Administrator switches them on.' ?></p>
            <p><button type="submit" class="lf-button">Save</button></p>
        </form>
    </section>
<?php endif; ?>
