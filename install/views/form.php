<?php

/**
 * The installation form: database, site, and administrator details on one page.
 *
 * @package LumoraFiction
 * @subpackage Installer
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

use LumoraFiction\Core\Security\Csrf;
use LumoraFiction\Installer\Html;

/** @var array<string, string> $errors field name => problem */
/** @var list<string> $formErrors problems that belong to the whole form */
/** @var array<string, string> $values what was typed, so it is not lost when a field is wrong */
/** @var string $installUrl */
/** @var string $baseUrl */
/** @var string $suggestedPrefix */
/** @var string $detectedSiteUrl */
/** @var list<string> $timezones */

$pageTitle = 'Install';

$value = static fn (string $name, string $default = ''): string => Html::e($values[$name] ?? $default);

/**
 * One labelled input, with its hint and any error wired to it for screen readers.
 *
 * @param array<string, string> $attributes extra attributes, already safe
 */
$field = static function (string $name, string $label, string $type, string $current, string $hint = '', array $attributes = []) use ($errors): string {
    $describedBy = [];
    $html = '<div class="field' . (isset($errors[$name]) ? ' field--error' : '') . '">'
        . '<label for="' . $name . '">' . Html::e($label) . '</label>';

    if ($hint !== '') {
        $describedBy[] = $name . '-hint';
    }

    if (isset($errors[$name])) {
        $describedBy[] = $name . '-error';
    }

    $extra = '';

    foreach ($attributes as $attribute => $attributeValue) {
        $extra .= ' ' . $attribute . ($attributeValue === '' ? '' : '="' . $attributeValue . '"');
    }

    $html .= '<input type="' . $type . '" id="' . $name . '" name="' . $name . '"'
        . ($type === 'password' ? '' : ' value="' . $current . '"')
        . ($describedBy !== [] ? ' aria-describedby="' . implode(' ', $describedBy) . '"' : '')
        . (isset($errors[$name]) ? ' aria-invalid="true"' : '')
        . $extra . '>';

    if ($hint !== '') {
        $html .= '<p class="hint" id="' . $name . '-hint">' . $hint . '</p>';
    }

    if (isset($errors[$name])) {
        $html .= '<p class="error" id="' . $name . '-error">' . Html::e($errors[$name]) . '</p>';
    }

    return $html . '</div>';
};

$selectedTimezone = $values['timezone'] ?? 'UTC';
$selectedMode = $values['archive_mode'] ?? 'both';

require __DIR__ . '/_head.php';
?>
        <h1>Install Lumora Fiction</h1>
        <p class="lead">Every story deserves a place. Fill in the details below and the site will be ready in a moment.</p>

        <p class="notice notice--warning" id="rewrite-warning" data-url="<?= Html::e($baseUrl) ?>/rewrite-check" hidden>
            Clean web addresses do not seem to work on this server, so links to stories and pages may not open.
            Ask your host to enable <code>mod_rewrite</code> (Apache) and to allow <code>.htaccess</code> files, then reload this page.
        </p>

        <?php if ($formErrors !== [] || $errors !== []): ?>
            <div class="notice notice--error" role="alert" id="error-summary" tabindex="-1">
                <h2>Please fix the following</h2>
                <ul>
                    <?php foreach ($formErrors as $message): ?>
                        <li><?= Html::e($message) ?></li>
                    <?php endforeach; ?>
                    <?php foreach ($errors as $name => $message): ?>
                        <li><a href="#<?= Html::e($name) ?>"><?= Html::e($message) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= Html::e($installUrl) ?>" novalidate>
            <?= Csrf::field('install') ?>

            <fieldset>
                <legend>Database</legend>
                <p class="hint">Create an empty database first, using your host's control panel, then enter its details here.</p>
                <?= $field('db_host', 'Database host', 'text', $value('db_host', 'localhost'), '', ['required' => '', 'autocomplete' => 'off']) ?>
                <?= $field('db_port', 'Database port', 'number', $value('db_port', '3306'), '', ['required' => '', 'min' => '1', 'max' => '65535', 'inputmode' => 'numeric']) ?>
                <?= $field('db_name', 'Database name', 'text', $value('db_name'), '', ['required' => '', 'autocomplete' => 'off']) ?>
                <?= $field('db_user', 'Database user', 'text', $value('db_user'), '', ['required' => '', 'autocomplete' => 'off']) ?>
                <?= $field('db_password', 'Database password', 'password', '', 'Not kept if the form is redisplayed, so enter it again if you need to correct something.', ['autocomplete' => 'off']) ?>
                <?= $field('table_prefix', 'Table prefix', 'text', $value('table_prefix', $suggestedPrefix), 'A random prefix has been made for you. It keeps this site\'s tables apart from anything else in the database. Avoid obvious values such as <code>wp_</code>.', ['required' => '', 'autocomplete' => 'off', 'spellcheck' => 'false']) ?>
            </fieldset>

            <fieldset>
                <legend>Your site</legend>
                <?= $field('site_name', 'Site name', 'text', $value('site_name'), '', ['required' => '', 'maxlength' => '100']) ?>
                <?= $field('site_url', 'Site address', 'url', $value('site_url', $detectedSiteUrl), 'Worked out from this request. Links in emails are built from it, so make sure it is the address people will really use, with no slash at the end.', ['required' => '', 'autocomplete' => 'url']) ?>

                <div class="field<?= isset($errors['timezone']) ? ' field--error' : '' ?>">
                    <label for="timezone">Time zone</label>
                    <select id="timezone" name="timezone" required<?= isset($errors['timezone']) ? ' aria-invalid="true" aria-describedby="timezone-error"' : '' ?>>
                        <?php foreach ($timezones as $timezone): ?>
                            <option value="<?= Html::e($timezone) ?>"<?= $timezone === $selectedTimezone ? ' selected' : '' ?>><?= Html::e($timezone) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['timezone'])): ?><p class="error" id="timezone-error"><?= Html::e($errors['timezone']) ?></p><?php endif; ?>
                </div>

                <fieldset class="choices">
                    <legend>What will the site publish?</legend>
                    <p class="hint" id="archive_mode-hint">You can change this later in the settings.</p>
                    <?php foreach (['both' => ['Fanfiction and original fiction', 'Both kinds of story, with the fields each needs.'], 'fanfiction' => ['Fanfiction only', 'Stories set in existing worlds, with fandoms, characters, and relationships.'], 'original' => ['Original fiction only', 'Stories in worlds of their own, without fandom fields.']] as $mode => [$title, $description]): ?>
                        <div class="choice">
                            <input type="radio" id="archive_mode_<?= Html::e($mode) ?>" name="archive_mode" value="<?= Html::e($mode) ?>"<?= $mode === $selectedMode ? ' checked' : '' ?> aria-describedby="archive_mode_<?= Html::e($mode) ?>-hint">
                            <label for="archive_mode_<?= Html::e($mode) ?>"><?= Html::e($title) ?></label>
                            <p class="hint" id="archive_mode_<?= Html::e($mode) ?>-hint"><?= Html::e($description) ?></p>
                        </div>
                    <?php endforeach; ?>
                    <?php if (isset($errors['archive_mode'])): ?><p class="error" id="archive_mode"><?= Html::e($errors['archive_mode']) ?></p><?php endif; ?>
                </fieldset>
            </fieldset>

            <fieldset>
                <legend>Administrator account</legend>
                <?= $field('admin_login_name', 'Login name', 'text', $value('admin_login_name'), 'Used only to sign in. It is never shown to anyone, so keep it different from your display name and avoid obvious choices like "admin".', ['required' => '', 'autocomplete' => 'username', 'autocapitalize' => 'none', 'spellcheck' => 'false']) ?>
                <?= $field('admin_display_name', 'Display name', 'text', $value('admin_display_name'), 'Shown publicly as your name on the site.', ['required' => '', 'autocomplete' => 'nickname', 'maxlength' => '100']) ?>
                <?= $field('admin_email', 'Email address', 'email', $value('admin_email'), '', ['required' => '', 'autocomplete' => 'email']) ?>
                <?= $field('admin_password', 'Password', 'password', '', 'At least 10 characters. A few ordinary words strung together works well.', ['required' => '', 'autocomplete' => 'new-password']) ?>
                <?= $field('admin_password_confirm', 'Confirm password', 'password', '', '', ['required' => '', 'autocomplete' => 'new-password']) ?>
            </fieldset>

            <p><button type="submit" class="button">Install Lumora Fiction</button></p>
        </form>

        <script src="<?= Html::e($installUrl) ?>assets/install.js" defer></script>
<?php require __DIR__ . '/_foot.php'; ?>
