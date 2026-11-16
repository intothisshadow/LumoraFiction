<?php

/**
 * Maintenance › eFiction importer: connect to the eFiction database.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;

/** @var array<string, mixed> $values */
/** @var array<string, string> $errors */
/** @var string $notice */
/** @var list<string> $folders */
/** @var bool $searched */
/** @var list<string> $prefixes */
/** @var list<string> $databases */
/** @var array<string, int> $made */
/** @var array{id: int, state: string, created_at: string}|null $latest */
/** @var bool $redirectsOn */
/** @var string $connectToken */
/** @var string $removeToken */
/** @var string $redirectToken */
/** @var string $forgetToken */
/** @var string $connectAction */
/** @var string $detectAction */
/** @var string $discoverAction */
/** @var string $removeAction */
/** @var string $redirectAction */
/** @var string $forgetAction */
/** @var string $reportUrl */
/** @var string $resumeUrl */

$field = static fn (string $name): string => Html::e((string) ($values[$name] ?? ''));
$hasPassword = (bool) ($values['has_password'] ?? false);
?>
<p class="lf-lead">Brings the authors, stories and chapters of an eFiction 3.5 archive across, through the same rules as anything written here. The eFiction database is only read, never changed. Take a backup of this site first, and read the plugin's README for what is and is not imported.</p>

<?php if ($errors !== []): ?>
    <div class="lf-notice lf-notice--error" role="alert" id="lf-error-summary" tabindex="-1">
        <p>Please fix the following:</p>
        <ul>
            <?php foreach ($errors as $name => $message): ?>
                <li><?= $name === 'form' ? Html::e($message) : '<a href="#' . Html::e($name) . '">' . Html::e($message) . '</a>' ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($notice !== ''): ?>
    <div class="lf-notice lf-notice--info" role="status"><p><?= Html::e($notice) ?></p></div>
<?php endif; ?>

<?php if ($resumeUrl !== ''): ?>
    <div class="lf-notice lf-notice--warning" role="status"><p>An import is part way through. <a href="<?= Html::e($resumeUrl) ?>">Carry it on</a> (you will be asked to connect again first if you have signed out).</p></div>
<?php endif; ?>

<form method="post" action="<?= Html::e($connectAction) ?>" class="lf-form" novalidate autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= Html::e($connectToken) ?>">

    <section class="lf-card" aria-labelledby="efi-folder-heading">
        <h2 id="efi-folder-heading">1. The eFiction folder (optional)</h2>
        <p class="lf-hint">If eFiction's files are on this server, naming the folder lets the importer read the database details from its config.php and find the story files. It never runs that file. You can skip this and fill in everything by hand.</p>

        <div class="lf-field<?= isset($errors['folder']) ? ' lf-field--error' : '' ?>">
            <label for="folder">Folder of the eFiction install</label>
            <input type="text" id="folder" name="folder" value="<?= $field('folder') ?>" maxlength="500">
            <?php if (isset($errors['folder'])): ?><p class="lf-error"><?= Html::e($errors['folder']) ?></p><?php endif; ?>
        </div>

        <?php if ($searched): ?>
            <?php if ($folders === []): ?>
                <p class="lf-hint">No eFiction folder was found next to this site. Type the folder above, or fill in the database details by hand.</p>
            <?php else: ?>
                <p id="efi-folders-label">Found next to this site:</p>
                <ul aria-labelledby="efi-folders-label">
                    <?php foreach ($folders as $folder): ?>
                        <li><code><?= Html::e($folder) ?></code> <button type="submit" class="lf-button lf-button--secondary" name="pick" value="<?= Html::e($folder) ?>" formaction="<?= Html::e($detectAction) ?>">Use this folder</button></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>

        <p>
            <button type="submit" class="lf-button lf-button--secondary" formaction="<?= Html::e($discoverAction) ?>">Discover folders</button>
            <button type="submit" class="lf-button lf-button--secondary" formaction="<?= Html::e($detectAction) ?>">Read the database details from this folder</button>
        </p>
    </section>

    <section class="lf-card" aria-labelledby="efi-db-heading">
        <h2 id="efi-db-heading">2. The eFiction database</h2>
        <p class="lf-hint">The account only needs permission to read (SELECT). The password is kept in your session while you work and dropped when you sign out or the run ends. It is never written to a file, a setting, the log or an address, and it is never shown again.</p>

        <?php foreach ([['host', 'Database host', 'text'], ['port', 'Port', 'text'], ['name', 'Database name', 'text'], ['user', 'User', 'text']] as [$name, $label, $type]): ?>
            <div class="lf-field<?= isset($errors[$name]) ? ' lf-field--error' : '' ?>">
                <label for="<?= Html::e($name) ?>"><?= Html::e($label) ?></label>
                <input type="<?= Html::e($type) ?>" id="<?= Html::e($name) ?>" name="<?= Html::e($name) ?>" value="<?= $field($name) ?>" maxlength="190"<?= $name === 'name' && $databases !== [] ? ' list="efi-databases"' : '' ?>>
                <?php if ($name === 'name' && $databases !== []): ?>
                    <datalist id="efi-databases">
                        <?php foreach ($databases as $database): ?><option value="<?= Html::e($database) ?>"><?php endforeach; ?>
                    </datalist>
                <?php endif; ?>
                <?php if (isset($errors[$name])): ?><p class="lf-error"><?= Html::e($errors[$name]) ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="lf-field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" value="" autocomplete="new-password" maxlength="200" aria-describedby="password_hint">
            <p class="lf-hint" id="password_hint"><?= $hasPassword ? 'A password is held for this session. Leave this empty to keep using it, or type another to replace it.' : 'Not saved anywhere but your session.' ?></p>
        </div>

        <div class="lf-field<?= isset($errors['prefix']) ? ' lf-field--error' : '' ?>">
            <label for="prefix">Table prefix</label>
            <input type="text" id="prefix" name="prefix" value="<?= $field('prefix') ?>" maxlength="40" aria-describedby="prefix_hint">
            <p class="lf-hint" id="prefix_hint">Everything before "stories" in the name of the eFiction stories table. It is usually <code>fanfiction_</code>.</p>
            <?php if (isset($errors['prefix'])): ?><p class="lf-error" id="prefix_error"><?= Html::e($errors['prefix']) ?></p><?php endif; ?>
            <?php if ($prefixes !== []): ?>
                <p id="efi-prefixes-label">These prefixes have the eFiction tables:</p>
                <ul aria-labelledby="efi-prefixes-label">
                    <?php foreach ($prefixes as $prefix): ?>
                        <li><code><?= Html::e($prefix === '' ? '(no prefix)' : $prefix) ?></code> <button type="submit" class="lf-button lf-button--secondary" name="use_prefix" value="<?= Html::e($prefix) ?>">Use this prefix</button></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="lf-field">
            <label for="stories">Folder of the story files (if the text is kept in files)</label>
            <input type="text" id="stories" name="stories" value="<?= $field('stories') ?>" maxlength="500" aria-describedby="stories_hint">
            <p class="lf-hint" id="stories_hint">Leave empty to use the <code>stories</code> folder inside the eFiction folder. Text kept in the database needs no folder.</p>
        </div>
    </section>

    <p><button type="submit" class="lf-button">Connect and preview</button></p>
</form>

<?php if ($made !== [] || $latest !== null): ?>
    <section class="lf-card" aria-labelledby="efi-earlier-heading">
        <h2 id="efi-earlier-heading">Earlier imports</h2>
        <?php if ($latest !== null): ?>
            <p>The latest run was <?= Html::e($latest['state'] === 'running' ? 'not finished' : $latest['state']) ?> (<?= Html::e($latest['created_at']) ?>). <a href="<?= Html::e($reportUrl) ?>">Read its report</a>.</p>
        <?php endif; ?>
        <?php if ($made !== []): ?>
            <p>Still here from imports: <strong><?= number_format($made['story'] ?? 0) ?></strong> stories, <strong><?= number_format($made['chapter'] ?? 0) ?></strong> chapters, <strong><?= number_format($made['user'] ?? 0) ?></strong> accounts, <strong><?= number_format($made['term'] ?? 0) ?></strong> classification terms, <strong><?= number_format($made['comment'] ?? 0) ?></strong> comments, <strong><?= number_format($made['bookmark'] ?? 0) ?></strong> bookmarks, <strong><?= number_format($made['news'] ?? 0) ?></strong> news posts and <strong><?= number_format($made['page'] ?? 0) ?></strong> pages.</p>
        <?php endif; ?>
    </section>

    <form method="post" action="<?= Html::e($redirectAction) ?>" class="lf-form">
        <input type="hidden" name="csrf_token" value="<?= Html::e($redirectToken) ?>">
        <section class="lf-card" aria-labelledby="efi-redirects-heading">
            <h2 id="efi-redirects-heading">Old addresses</h2>
            <p class="lf-hint">Only useful when Lumora Fiction now answers at the address eFiction had, so that <code>viewstory.php?sid=…</code>, <code>viewuser.php?uid=…</code> and <code>viewpage.php?page=…</code> reach this site. Each is sent on with a permanent redirect, and only for something that was imported.</p>
            <div class="lf-choice">
                <input type="checkbox" id="redirects" name="redirects" value="1"<?= $redirectsOn ? ' checked' : '' ?>>
                <label for="redirects">Send the old eFiction addresses to the imported stories, authors and pages</label>
            </div>
            <p><button type="submit" class="lf-button lf-button--secondary">Save</button></p>
        </section>
    </form>

    <?php if ($made !== []): ?>
        <form method="post" action="<?= Html::e($removeAction) ?>" class="lf-form">
            <input type="hidden" name="csrf_token" value="<?= Html::e($removeToken) ?>">
            <section class="lf-card" aria-labelledby="efi-remove-heading">
                <h2 id="efi-remove-heading">Remove everything this plugin imported</h2>
                <p>This deletes the <strong><?= number_format($made['story'] ?? 0) ?></strong> stories with their <strong><?= number_format($made['chapter'] ?? 0) ?></strong> chapters, <strong><?= number_format($made['comment'] ?? 0) ?></strong> comments, <strong><?= number_format($made['bookmark'] ?? 0) ?></strong> bookmarks, <strong><?= number_format($made['news'] ?? 0) ?></strong> news posts, <strong><?= number_format($made['page'] ?? 0) ?></strong> pages, <strong><?= number_format($made['term'] ?? 0) ?></strong> classification terms and <strong><?= number_format($made['user'] ?? 0) ?></strong> accounts that imports made. Nothing written by hand is touched, and an account or term that only matched one that was already here is left alone. This cannot be undone.</p>
                <div class="lf-choice">
                    <input type="checkbox" id="remove-confirm" name="confirm" value="1">
                    <label for="remove-confirm">Yes, remove everything the importer made</label>
                </div>
                <p><button type="submit" class="lf-button lf-button--secondary">Remove imported content</button></p>
            </section>
        </form>
    <?php endif; ?>
<?php endif; ?>

<form method="post" action="<?= Html::e($forgetAction) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($forgetToken) ?>">
    <p><button type="submit" class="lf-button lf-button--secondary">Drop the held connection and password</button></p>
</form>
