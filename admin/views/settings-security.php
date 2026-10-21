<?php

/**
 * Settings › Security.
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

/** @var array<string, int|string|bool> $values */
/** @var array<string, string> $errors */
/** @var string $peer */
/** @var string $address */
/** @var bool $https */
/** @var string $csrfToken */
/** @var string $action */

$behindProxy = $peer !== $address;
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

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <fieldset class="lf-card">
        <legend>Sign-in lockout</legend>
        <p class="lf-hint">After too many wrong passwords from one internet address, that address cannot try again for a while. Nobody else is affected, and a correct sign-in is never blocked for any other address.</p>

        <div class="lf-field<?= isset($errors['lockout_attempts']) ? ' lf-field--error' : '' ?>">
            <label for="lockout_attempts">Wrong passwords allowed</label>
            <input type="text" inputmode="numeric" id="lockout_attempts" name="lockout_attempts" value="<?= Html::e((string) $values['lockout_attempts']) ?>" maxlength="2" aria-describedby="lockout_attempts_hint<?= isset($errors['lockout_attempts']) ? ' lockout_attempts_error' : '' ?>">
            <p class="lf-hint" id="lockout_attempts_hint">The address is locked out when it reaches this number, counted within the time below. From 3 to 20; the usual choice is 5.</p>
            <?php if (isset($errors['lockout_attempts'])): ?><p class="lf-error" id="lockout_attempts_error"><?= Html::e($errors['lockout_attempts']) ?></p><?php endif; ?>
        </div>

        <div class="lf-field<?= isset($errors['lockout_minutes']) ? ' lf-field--error' : '' ?>">
            <label for="lockout_minutes">Minutes of lockout</label>
            <input type="text" inputmode="numeric" id="lockout_minutes" name="lockout_minutes" value="<?= Html::e((string) $values['lockout_minutes']) ?>" maxlength="4" aria-describedby="lockout_minutes_hint<?= isset($errors['lockout_minutes']) ? ' lockout_minutes_error' : '' ?>">
            <p class="lf-hint" id="lockout_minutes_hint">How long the lockout lasts, and how far back wrong passwords are counted. From 1 to 1440; the usual choice is 15.</p>
            <?php if (isset($errors['lockout_minutes'])): ?><p class="lf-error" id="lockout_minutes_error"><?= Html::e($errors['lockout_minutes']) ?></p><?php endif; ?>
        </div>

        <div class="lf-choice">
            <input type="checkbox" id="lockout_progressive" name="lockout_progressive" value="1"<?= $values['lockout_progressive'] === true ? ' checked' : '' ?> aria-describedby="lockout_progressive_hint">
            <label for="lockout_progressive">Lock out longer an address that keeps failing</label>
            <p class="lf-hint" id="lockout_progressive_hint">Each further batch of wrong passwords from the same address within a day doubles the lockout, up to sixteen times as long.</p>
        </div>

        <div class="lf-choice">
            <input type="checkbox" id="notify_owner" name="notify_owner" value="1"<?= $values['notify_owner'] === true ? ' checked' : '' ?> aria-describedby="notify_owner_hint">
            <label for="notify_owner">Email the owner of an account that is being guessed at</label>
            <p class="lf-hint" id="notify_owner_hint">When a lockout starts and the last sign-in named an account, its owner gets one message, at most once an hour. Nothing on the sign-in page changes, so it does not tell anyone whether an account exists.</p>
        </div>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Reverse proxy or CDN</legend>
        <p class="lf-hint">If the site sits behind a proxy or a CDN such as Cloudflare, every visit seems to come from the proxy, so the lockout and the registration limits would treat all your visitors as one. List the proxies here and the site will read the visitor's address, and whether they used HTTPS, from the headers the proxy adds. Leave this empty if there is no proxy. List only addresses you control: the site believes those headers from anyone on this list, and a wrong entry lets a visitor choose the address that is recorded.</p>

        <p class="lf-hint">This page reached the site from <strong><?= Html::e($peer) ?></strong><?= $behindProxy ? ', and the site recorded your address as <strong>' . Html::e($address) . '</strong>' : '' ?>. The connection is treated as <?= $https ? 'HTTPS' : 'plain HTTP' ?>.
        <?php if (!$behindProxy): ?>If you use a proxy and this shows the proxy's address, add it below.<?php endif; ?></p>

        <div class="lf-field<?= isset($errors['trusted_proxies']) ? ' lf-field--error' : '' ?>">
            <label for="trusted_proxies">Trusted proxies</label>
            <textarea id="trusted_proxies" name="trusted_proxies" rows="5" spellcheck="false" aria-describedby="trusted_proxies_hint<?= isset($errors['trusted_proxies']) ? ' trusted_proxies_error' : '' ?>"><?= Html::e((string) $values['trusted_proxies']) ?></textarea>
            <p class="lf-hint" id="trusted_proxies_hint">One address or range per line, such as <code>10.0.0.5</code> or <code>192.168.0.0/16</code>. Lines starting with # are ignored.</p>
            <?php if (isset($errors['trusted_proxies'])): ?><p class="lf-error" id="trusted_proxies_error"><?= Html::e($errors['trusted_proxies']) ?></p><?php endif; ?>
        </div>
    </fieldset>

    <p><button type="submit" class="lf-button">Save settings</button></p>
</form>
