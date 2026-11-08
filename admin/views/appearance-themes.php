<?php

/**
 * Appearance › Themes: the installed themes, which one the site uses, and adding, updating and deleting themes.
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
use LumoraFiction\Core\Theme\ThemeInfo;
use LumoraFiction\Helpers\Html;

/** @var list<array{theme: ThemeInfo, active: bool, screenshot: string, problems: list<string>, selectable: bool, previewUrl: string}> $cards */
/** @var string|null $previewing the theme this Administrator is previewing on the site right now */
/** @var array{enabled: bool, ticked: list<string>} $visitorChoice */
/** @var string $visitorToken */
/** @var string $visitorAction */
/** @var string $chosenMissing */
/** @var string $activateToken */
/** @var string $installToken */
/** @var string $updateToken */
/** @var string $bulkToken */
/** @var string $activateAction */
/** @var string $installAction */
/** @var string $updateAction */
/** @var string $bulkAction */
/** @var string $optionsUrl */
/** @var bool $uploadsAllowed */
/** @var bool $canWrite */
/** @var int $maxUploadMegabytes */
/** @var string|null $assetVersion */

$anySelectable = array_filter($cards, static fn (array $card): bool => $card['selectable']) !== [];
$canUpload = $uploadsAllowed && $canWrite;
?>
<?php if ($chosenMissing !== ''): ?>
    <div class="lf-notice lf-notice--warning" role="status">
        <p>The theme you chose (<?= Html::e($chosenMissing) ?>) is not installed or cannot be read, so the built-in theme is being used. Add the theme again below, or choose another theme.</p>
    </div>
<?php endif; ?>

<p class="lf-lead">Themes control how the public side of the site looks. Add one from a ZIP file below, or copy its folder into the themes folder of the site. <strong>Preview</strong> shows a theme on the real site, on every page, to you alone, before you make it the active one.</p>

<?php if ($previewing !== null): ?>
    <div class="lf-notice lf-notice--info" role="status">
        <p>You are previewing a theme on the public site (<?= Html::e($previewing) ?>). A banner on every page there lets you make it the active theme or leave the preview. Nobody else sees it.</p>
    </div>
<?php endif; ?>

<?php if ($anySelectable): ?>
    <form id="lf-themes-bulk" method="post" action="<?= Html::e($bulkAction) ?>" class="lf-bulk">
        <input type="hidden" name="csrf_token" value="<?= Html::e($bulkToken) ?>">
        <label for="lf-themes-bulk-action">With the ticked themes</label>
        <select id="lf-themes-bulk-action" name="bulk_action">
            <option value="">Choose&hellip;</option>
            <option value="delete">Delete</option>
        </select>
        <button type="submit" class="lf-button lf-button--quiet">Apply</button>
    </form>
<?php endif; ?>

<ul class="lf-theme-list">
    <?php foreach ($cards as $card): ?>
        <?php
        $theme = $card['theme'];
        $detailsId = 'lf-theme-details-' . $theme->slug;
        ?>
        <li class="lf-card lf-theme-card<?= $card['active'] ? ' lf-theme-card--active' : '' ?>">
            <?php if ($card['selectable']): ?>
                <label class="lf-theme-card__select">
                    <input type="checkbox" form="lf-themes-bulk" name="themes[]" value="<?= Html::e($theme->slug) ?>">
                    <span class="lf-visually-hidden">Select &ldquo;<?= Html::e($theme->name) ?>&rdquo;</span>
                </label>
            <?php endif; ?>

            <?php if ($card['screenshot'] !== ''): ?>
                <img class="lf-theme-card__shot" src="<?= Html::e($card['screenshot']) ?>" alt="" loading="lazy">
            <?php else: ?>
                <div class="lf-theme-card__shot lf-theme-card__shot--empty" aria-hidden="true"></div>
            <?php endif; ?>

            <h2 class="lf-theme-card__name">
                <?= Html::e($theme->name) ?>
                <?= $card['active'] ? ' <span class="lf-badge">Active</span>' : '' ?>
            </h2>
            <p class="lf-hint">
                Version <?= Html::e($theme->version) ?><?= $theme->author !== '' ? ' &middot; by ' . Html::e($theme->author) : '' ?>
            </p>
            <?php if ($theme->description !== ''): ?><p class="lf-theme-card__description"><?= Html::e($theme->description) ?></p><?php endif; ?>

            <?php foreach ($card['problems'] as $problem): ?>
                <p class="lf-notice lf-notice--warning" role="status"><?= Html::e($problem) ?></p>
            <?php endforeach; ?>

            <div class="lf-theme-card__actions">
                <a class="lf-button lf-button--quiet" href="#<?= Html::e($detailsId) ?>" data-lf-theme-details="<?= Html::e($detailsId) ?>">Details<span class="lf-visually-hidden"> of <?= Html::e($theme->name) ?></span></a>
                <?php if (($card['previewUrl'] ?? '') !== ''): ?>
                    <a class="lf-button lf-button--quiet" href="<?= Html::e($card['previewUrl']) ?>">Preview<span class="lf-visually-hidden"> the <?= Html::e($theme->name) ?> theme on the site</span></a>
                <?php endif; ?>
                <?php if ($card['active']): ?>
                    <a class="lf-button lf-button--quiet" href="<?= Html::e($optionsUrl) ?>">Theme options</a>
                <?php elseif ($card['problems'] === []): ?>
                    <form method="post" action="<?= Html::e($activateAction) ?>">
                        <input type="hidden" name="csrf_token" value="<?= Html::e($activateToken) ?>">
                        <input type="hidden" name="theme" value="<?= Html::e($theme->slug) ?>">
                        <button type="submit" class="lf-button">Use this theme</button>
                    </form>
                <?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
</ul>

<section class="lf-card lf-theme-visitors" aria-labelledby="lf-theme-visitors-title">
    <h2 id="lf-theme-visitors-title">Let visitors choose their own theme</h2>
    <form method="post" action="<?= Html::e($visitorAction) ?>" class="lf-form">
        <input type="hidden" name="csrf_token" value="<?= Html::e($visitorToken) ?>">
        <div class="lf-choice">
            <input type="checkbox" id="visitor_choice" name="visitor_choice" value="1"<?= $visitorChoice['enabled'] ? ' checked' : '' ?> aria-describedby="visitor_choice_hint">
            <label for="visitor_choice">Let visitors choose their own theme</label>
        </div>
        <p class="lf-hint" id="visitor_choice_hint">Off by default. When on, a small form on every page lets a visitor pick one of the themes below. A signed-in reader&rsquo;s choice follows them to any device; anyone else gets a small cookie that holds only the theme&rsquo;s folder name. The admin area, downloads and feeds always use the site&rsquo;s theme.</p>
        <fieldset class="lf-theme-visitors__themes">
            <legend>Themes visitors may pick from</legend>
            <p class="lf-hint">The active theme is always on the list and is the default for anyone who has not chosen. Tick the others to offer. Nothing is shown to visitors unless two or more themes are offered.</p>
            <?php foreach ($cards as $card): ?>
                <?php $theme = $card['theme']; ?>
                <?php if ($theme->builtIn): ?>
                    <?php continue; ?>
                <?php endif; ?>
                <div class="lf-choice">
                    <?php if ($card['active']): ?>
                        <input type="checkbox" id="visitor_theme_<?= Html::e($theme->slug) ?>" checked disabled>
                        <label for="visitor_theme_<?= Html::e($theme->slug) ?>"><?= Html::e($theme->name) ?> (the active theme, always offered)</label>
                    <?php else: ?>
                        <input type="checkbox" id="visitor_theme_<?= Html::e($theme->slug) ?>" name="visitor_themes[]" value="<?= Html::e($theme->slug) ?>"<?= in_array($theme->slug, $visitorChoice['ticked'], true) ? ' checked' : '' ?><?= $card['problems'] !== [] ? ' disabled' : '' ?>>
                        <label for="visitor_theme_<?= Html::e($theme->slug) ?>"><?= Html::e($theme->name) ?><?= $card['problems'] !== [] ? ' (cannot be used on this site)' : '' ?></label>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </fieldset>
        <p><button type="submit" class="lf-button lf-button--secondary">Save</button></p>
    </form>
</section>

<section class="lf-card lf-theme-install" aria-labelledby="lf-theme-install-title">
    <h2 id="lf-theme-install-title">Add a theme</h2>
    <?php if (!$uploadsAllowed): ?>
        <p class="lf-notice lf-notice--info">Adding themes from a ZIP is turned off for this site. To add a theme, copy its folder into the themes folder of the site.</p>
    <?php elseif (!$canWrite): ?>
        <p class="lf-notice lf-notice--warning">The web server cannot write to the themes folder, so themes cannot be added or removed here. Copy the theme&rsquo;s folder into the themes folder by hand, or give the server permission to write to it.</p>
    <?php else: ?>
        <p class="lf-notice lf-notice--warning">Upload a .zip archive containing a theme folder. The destination folder name is derived from the theme&rsquo;s declared name.</p>
        <form method="post" action="<?= Html::e($installAction) ?>" enctype="multipart/form-data" class="lf-form">
            <input type="hidden" name="csrf_token" value="<?= Html::e($installToken) ?>">
            <div class="lf-field">
                <label for="lf-theme-zip">Theme ZIP file</label>
                <input type="file" id="lf-theme-zip" name="theme_zip" accept=".zip,application/zip" required aria-describedby="lf-theme-zip-hint">
                <p class="lf-hint" id="lf-theme-zip-hint">A ZIP of one theme folder, up to <?= (int) $maxUploadMegabytes ?> MB. To replace a theme you already have, use Update in its Details.</p>
            </div>
            <p><button type="submit" class="lf-button">Add theme</button></p>
        </form>
    <?php endif; ?>
</section>

<section class="lf-theme-details-area" data-lf-details-area aria-labelledby="lf-theme-details-title">
    <h2 id="lf-theme-details-title">Theme details</h2>
    <?php foreach ($cards as $card): ?>
        <?php
        $theme = $card['theme'];
        $detailsId = 'lf-theme-details-' . $theme->slug;
        ?>
        <section class="lf-card lf-theme-details" id="<?= Html::e($detailsId) ?>" aria-labelledby="<?= Html::e($detailsId) ?>-title" tabindex="-1">
            <h3 id="<?= Html::e($detailsId) ?>-title">
                <?= Html::e($theme->name) ?>
                <?= $card['active'] ? ' <span class="lf-badge">Active</span>' : '' ?>
            </h3>

            <?php if ($card['screenshot'] !== ''): ?>
                <img class="lf-theme-details__shot" src="<?= Html::e($card['screenshot']) ?>" alt="Preview of the <?= Html::e($theme->name) ?> theme" loading="lazy">
            <?php else: ?>
                <div class="lf-theme-details__shot lf-theme-details__shot--empty" aria-hidden="true"></div>
            <?php endif; ?>

            <?php if ($theme->description !== ''): ?><p><?= Html::e($theme->description) ?></p><?php endif; ?>

            <?php foreach ($card['problems'] as $problem): ?>
                <p class="lf-notice lf-notice--warning" role="status"><?= Html::e($problem) ?></p>
            <?php endforeach; ?>

            <dl class="lf-theme-details__facts">
                <?php if ($theme->author !== ''): ?>
                    <dt>Author</dt>
                    <dd><?= Html::e($theme->author) ?></dd>
                <?php endif; ?>
                <?php if ($theme->designUri !== ''): ?>
                    <dt>Design URI</dt>
                    <dd><a href="<?= Html::e($theme->designUri) ?>" rel="noopener noreferrer nofollow"><?= Html::e($theme->designUri) ?></a></dd>
                <?php endif; ?>
                <dt>Version</dt>
                <dd><?= Html::e($theme->version) ?></dd>
                <dt>Folder</dt>
                <dd><?= Html::e($theme->slug) ?></dd>
            </dl>

            <div class="lf-theme-details__actions">
                <?php if (($card['previewUrl'] ?? '') !== ''): ?>
                    <a class="lf-button lf-button--quiet" href="<?= Html::e($card['previewUrl']) ?>">Preview<span class="lf-visually-hidden"> the <?= Html::e($theme->name) ?> theme on the site</span></a>
                <?php endif; ?>
                <?php if ($card['active']): ?>
                    <a class="lf-button lf-button--quiet" href="<?= Html::e($optionsUrl) ?>">Theme options</a>
                <?php elseif ($card['problems'] === []): ?>
                    <form method="post" action="<?= Html::e($activateAction) ?>">
                        <input type="hidden" name="csrf_token" value="<?= Html::e($activateToken) ?>">
                        <input type="hidden" name="theme" value="<?= Html::e($theme->slug) ?>">
                        <button type="submit" class="lf-button">Use this theme</button>
                    </form>
                <?php endif; ?>

                <?php if ($card['selectable'] && $canWrite): ?>
                    <form method="post" action="<?= Html::e($bulkAction) ?>">
                        <input type="hidden" name="csrf_token" value="<?= Html::e($bulkToken) ?>">
                        <input type="hidden" name="bulk_action" value="delete">
                        <input type="hidden" name="themes[]" value="<?= Html::e($theme->slug) ?>">
                        <button type="submit" class="lf-button lf-button--secondary">Delete&hellip;</button>
                    </form>
                <?php endif; ?>

                <button type="button" class="lf-button lf-button--quiet" data-lf-dialog-close hidden>Close</button>
            </div>

            <?php if (!$theme->builtIn && $canUpload): ?>
                <form method="post" action="<?= Html::e($updateAction) ?>" enctype="multipart/form-data" class="lf-form lf-theme-details__update">
                    <input type="hidden" name="csrf_token" value="<?= Html::e($updateToken) ?>">
                    <input type="hidden" name="theme" value="<?= Html::e($theme->slug) ?>">
                    <div class="lf-field">
                        <label for="<?= Html::e($detailsId) ?>-zip">Update from a ZIP</label>
                        <input type="file" id="<?= Html::e($detailsId) ?>-zip" name="theme_zip" accept=".zip,application/zip" required>
                        <p class="lf-hint">The files are replaced and your saved options for this theme are kept.</p>
                    </div>
                    <div class="lf-field">
                        <div class="lf-choice">
                            <input type="checkbox" id="<?= Html::e($detailsId) ?>-older" name="allow_older" value="1">
                            <label for="<?= Html::e($detailsId) ?>-older">Install it even if it is the same version or older</label>
                        </div>
                    </div>
                    <p><button type="submit" class="lf-button lf-button--secondary">Update theme</button></p>
                </form>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</section>

<dialog class="lf-dialog" data-lf-theme-dialog aria-label="Theme details"></dialog>

<script src="<?= Html::e(AdminUrl::asset('js/theme-details.js', $assetVersion ?? '')) ?>" defer></script>
