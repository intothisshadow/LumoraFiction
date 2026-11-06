<?php

/**
 * Compares the stored site address and folder with where the site is being opened from, and lets an administrator correct them.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;

/** @var string $configured the address in config.php */
/** @var string $current the address this page was opened on */
/** @var array{url: string, path: string} $record */
/** @var string $currentPath */
/** @var array{configured: string, current: string, recordedPath: string, currentPath: string, addressChanged: bool, pathChanged: bool}|null $mismatch */
/** @var string $proposed */
/** @var list<string> $kept */
/** @var bool $writable */
/** @var string|null $manualLine */
/** @var array<string, string> $errors */
/** @var string $csrfToken */
/** @var string $action */
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

<p class="lf-lead">After a site is moved to a new domain, to HTTPS, to another folder of the same host or to another server, the address saved in the site's configuration still points at the old place. Links in emails, feeds and the sitemap are built from that address, so they would lead to the wrong site. The address is never changed by itself: a visitor can send any host name, so it only changes here, when you confirm it.</p>

<?php if ($manualLine !== null): ?>
    <div class="lf-notice lf-notice--warning" role="status">
        <p><strong>The configuration file cannot be written by the web server, so nothing was changed.</strong> Open <code>config/config.php</code> on the server and replace the <code>site_url</code> line with:</p>
        <p><code><?= Html::e($manualLine) ?></code></p>
        <p>Then reload this page. Or let the web server write that one file and press the button again.</p>
    </div>
<?php elseif ($mismatch !== null): ?>
    <div class="lf-notice lf-notice--warning" role="status">
        <p><strong>This site looks as if it has been moved.</strong>
        <?php if ($mismatch['addressChanged'] && $mismatch['pathChanged']): ?>
            It is being opened on a different address, and its folder on the server has changed.
        <?php elseif ($mismatch['addressChanged']): ?>
            It is being opened on a different address than the one saved for it.
        <?php else: ?>
            Its folder on the server is not where it was last confirmed.
        <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <p class="lf-notice lf-notice--success" role="status">The saved address and folder match where the site is being opened. Nothing needs changing.</p>
<?php endif; ?>

<table class="lf-table">
    <caption class="lf-visually-hidden">Saved and detected site address and folder</caption>
    <thead>
        <tr>
            <th scope="col">What</th>
            <th scope="col">Saved</th>
            <th scope="col">Detected now</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <th scope="row">Site address</th>
            <td><?= $configured === '' ? 'Not set' : Html::e($configured) ?></td>
            <td><?= $current === '' ? 'Unknown' : Html::e($current) ?></td>
        </tr>
        <tr>
            <th scope="row">Folder on the server</th>
            <td><?= $record['path'] === '' ? 'Not recorded' : Html::e($record['path']) ?></td>
            <td><?= Html::e($currentPath) ?></td>
        </tr>
    </tbody>
</table>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <fieldset class="lf-card">
        <legend>Update the address and folder</legend>

        <div class="lf-field<?= isset($errors['address']) ? ' lf-field--error' : '' ?>">
            <label for="address">Site address</label>
            <input type="url" id="address" name="address" value="<?= Html::e($proposed) ?>" maxlength="255" aria-describedby="address_hint<?= isset($errors['address']) ? ' address_error' : '' ?>">
            <p class="lf-hint" id="address_hint">Suggested from the address you are using now. Check it, correct it if it is wrong (for example when a proxy hides the real one), and include the folder if the site is not at the top of its domain.</p>
            <?php if (isset($errors['address'])): ?><p class="lf-field__error" id="address_error"><?= Html::e($errors['address']) ?></p><?php endif; ?>
        </div>

        <p class="lf-hint">Updating changes the links in emails, feeds, canonical addresses and the sitemap from now on. Nothing already written is rewritten: stories, chapters and links typed inside them, and files already built keep whatever they say. The database, the table prefix and the secret key are not touched.</p>

        <p><button type="submit" name="choice" value="update" class="lf-button">Update address and folder</button></p>
    </fieldset>

    <?php if ($mismatch !== null && $mismatch['addressChanged']): ?>
        <fieldset class="lf-card">
            <legend>Keep the old address</legend>
            <p class="lf-hint">If the site is meant to be reachable under both names (a test address, a CDN or a temporary host), keep the saved address and stop being told about <code><?= Html::e($mismatch['current']) ?></code>.</p>
            <p><button type="submit" name="choice" value="keep" class="lf-button lf-button--secondary">Keep the old address</button></p>
        </fieldset>
    <?php endif; ?>

    <?php if ($kept !== []): ?>
        <fieldset class="lf-card">
            <legend>Addresses you chose to keep</legend>
            <ul>
                <?php foreach ($kept as $address): ?><li><?= Html::e($address) ?></li><?php endforeach; ?>
            </ul>
            <p><button type="submit" name="choice" value="reset" class="lf-button lf-button--secondary">Stop keeping these</button></p>
        </fieldset>
    <?php endif; ?>
</form>

<?php if (!$writable): ?>
    <p class="lf-hint">The web server cannot write <code>config/config.php</code> here. If you press Update, you will be shown the exact line to change by hand.</p>
<?php endif; ?>
