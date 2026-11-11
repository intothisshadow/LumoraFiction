<?php

/**
 * Admin view: the cache settings form and the header check.
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

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Helpers\Html;

/** @var array{enabled: bool, types: list<string>, lifetime: int, browser_lifetime: int, exclusions: string, driver: string} $values */
/** @var array<string, string> $errors */
/** @var array<string, string> $kinds */
/** @var array<string, string> $drivers */
/** @var list<string> $warnings */
/** @var bool $detected */
/** @var string $driverInUse */
/** @var list<array{what: string, at: string}> $purges */
/** @var array<string, string> $samples */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $assetVersion */
?>
<?php if ($errors !== []): ?>
    <div class="lf-notice lf-notice--error" role="alert" id="lf-error-summary" tabindex="-1">
        <p>Please fix the following:</p>
        <ul>
            <?php foreach ($errors as $field => $message): ?>
                <li><?= $field === 'form' ? Html::e($message) : '<a href="#' . Html::e($field) . '">' . Html::e($message) . '</a>' ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php foreach ($warnings as $warning): ?>
    <div class="lf-notice lf-notice--warning" role="status"><p><?= Html::e($warning) ?></p></div>
<?php endforeach; ?>

<p class="lf-lead">Public pages are sent with headers that let a browser or a cache reuse them, and a visitor who already holds the current page is answered with a short &ldquo;not modified&rdquo; reply. Signed-in readers, drafts, previews, the admin, accounts and any page that sets a cookie are never cached.</p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <section class="lf-card" aria-labelledby="lf-cache-what-heading">
        <h2 id="lf-cache-what-heading">What gets cached</h2>

        <div class="lf-choice">
            <input type="checkbox" id="enabled" name="enabled" value="1"<?= $values['enabled'] ? ' checked' : '' ?> aria-describedby="enabled_hint">
            <label for="enabled">Send cache headers on public pages</label>
        </div>
        <p class="lf-hint" id="enabled_hint">Off, every page is sent as not to be kept. Changes to the site empty an outside cache either way.</p>

        <fieldset class="lf-field">
            <legend>Kinds of page</legend>
            <?php foreach ($kinds as $kind => $label): ?>
                <div class="lf-choice">
                    <input type="checkbox" id="types_<?= Html::e($kind) ?>" name="types[]" value="<?= Html::e($kind) ?>"<?= in_array($kind, $values['types'], true) ? ' checked' : '' ?>>
                    <label for="types_<?= Html::e($kind) ?>"><?= Html::e($label) ?></label>
                </div>
            <?php endforeach; ?>
        </fieldset>

        <div class="lf-field<?= isset($errors['exclusions']) ? ' lf-field--error' : '' ?>">
            <label for="exclusions">Never cache these paths</label>
            <textarea id="exclusions" name="exclusions" rows="4" aria-describedby="exclusions_hint<?= isset($errors['exclusions']) ? ' exclusions_error' : '' ?>"><?= Html::e($values['exclusions']) ?></textarea>
            <p class="lf-hint" id="exclusions_hint">One path per line, such as <code>/news</code> or <code>/story/*</code>. A <code>*</code> stands for any run of characters. The query string is ignored. Lines that start with <code>#</code> are notes.</p>
            <?php if (isset($errors['exclusions'])): ?><p class="lf-error" id="exclusions_error"><?= Html::e($errors['exclusions']) ?></p><?php endif; ?>
        </div>
    </section>

    <section class="lf-card" aria-labelledby="lf-cache-time-heading">
        <h2 id="lf-cache-time-heading">How long</h2>

        <div class="lf-field<?= isset($errors['lifetime']) ? ' lf-field--error' : '' ?>">
            <label for="lifetime">Lifetime in a shared cache (seconds)</label>
            <input type="text" inputmode="numeric" id="lifetime" name="lifetime" value="<?= Html::e((string) $values['lifetime']) ?>" maxlength="8" aria-describedby="lifetime_hint<?= isset($errors['lifetime']) ? ' lifetime_error' : '' ?>">
            <p class="lf-hint" id="lifetime_hint">How long a cache in front of the site, such as LiteSpeed, may keep a page before asking again. The usual choice is 3600 (an hour). Changes to a story, the news or the site empty the pages they affect straight away.</p>
            <?php if (isset($errors['lifetime'])): ?><p class="lf-error" id="lifetime_error"><?= Html::e($errors['lifetime']) ?></p><?php endif; ?>
        </div>

        <div class="lf-field<?= isset($errors['browser_lifetime']) ? ' lf-field--error' : '' ?>">
            <label for="browser_lifetime">Lifetime in a visitor&rsquo;s browser (seconds)</label>
            <input type="text" inputmode="numeric" id="browser_lifetime" name="browser_lifetime" value="<?= Html::e((string) $values['browser_lifetime']) ?>" maxlength="8" aria-describedby="browser_lifetime_hint<?= isset($errors['browser_lifetime']) ? ' browser_lifetime_error' : '' ?>">
            <p class="lf-hint" id="browser_lifetime_hint">0 makes a browser check with the site on every visit, which costs a small reply and means a change is never hidden. A higher number saves more, but visitors keep seeing the old page for that long after a change, because emptying the shared cache cannot reach their browsers. It cannot be higher than the lifetime above.</p>
            <?php if (isset($errors['browser_lifetime'])): ?><p class="lf-error" id="browser_lifetime_error"><?= Html::e($errors['browser_lifetime']) ?></p><?php endif; ?>
        </div>
    </section>

    <section class="lf-card" aria-labelledby="lf-cache-driver-heading">
        <h2 id="lf-cache-driver-heading">Outside cache</h2>

        <div class="lf-field<?= isset($errors['driver']) ? ' lf-field--error' : '' ?>">
            <label for="driver">Cache to tell about changes</label>
            <select id="driver" name="driver" aria-describedby="driver_hint">
                <?php foreach ($drivers as $key => $label): ?>
                    <option value="<?= Html::e($key) ?>"<?= $values['driver'] === $key ? ' selected' : '' ?>><?= Html::e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="lf-hint" id="driver_hint">LiteSpeed Cache is told which pages to forget when a story, chapter, comment, news post or setting changes. This server <?= $detected ? 'says it is LiteSpeed' : 'does not say it is LiteSpeed' ?>; in use now: <strong><?= Html::e($driverInUse === 'litespeed' ? 'LiteSpeed Cache' : 'none') ?></strong>.</p>
            <?php if (isset($errors['driver'])): ?><p class="lf-error"><?= Html::e($errors['driver']) ?></p><?php endif; ?>
        </div>
    </section>

    <p><button type="submit" class="lf-button">Save settings</button></p>
</form>

<section class="lf-card" aria-labelledby="lf-cache-test-heading" data-lf-cache-test>
    <h2 id="lf-cache-test-heading">Check what is sent</h2>
    <p class="lf-hint">Asks for a page the way a visitor who is not signed in would, then asks again as a browser that already holds it, and shows the headers of both answers. This needs JavaScript.</p>

    <div class="lf-field">
        <label for="cache-test-path">Page to check</label>
        <input type="text" id="cache-test-path" value="<?= Html::e((string) reset($samples)) ?>" list="cache-test-samples" autocomplete="off">
        <datalist id="cache-test-samples">
            <?php foreach ($samples as $label => $path): ?><option value="<?= Html::e($path) ?>"><?= Html::e($label) ?></option><?php endforeach; ?>
        </datalist>
    </div>
    <p><button type="button" class="lf-button lf-button--secondary" data-lf-cache-test-run>Check this page</button></p>
    <div data-lf-cache-test-result aria-live="polite"></div>
    <script src="<?= Html::e(AdminUrl::asset('js/cache-test.js', $assetVersion)) ?>" defer></script>
</section>

<section class="lf-card" aria-labelledby="lf-cache-purges-heading">
    <h2 id="lf-cache-purges-heading">Recent purges</h2>
    <?php if ($purges === []): ?>
        <p class="lf-hint">None yet. A purge is noted here each time an outside cache is told to forget pages.</p>
    <?php else: ?>
        <table class="lf-table">
            <thead><tr><th scope="col">When (UTC)</th><th scope="col">What was emptied</th></tr></thead>
            <tbody>
                <?php foreach ($purges as $purge): ?>
                    <tr><td><?= Html::e($purge['at']) ?></td><td><?= Html::e($purge['what']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
