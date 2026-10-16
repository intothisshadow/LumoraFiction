<?php

/**
 * The admin sign-in form.
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

/** @var string|null $error */
/** @var string $identity */
/** @var string $csrfToken */
/** @var list<array{type: string, message: string}> $messages */
/** @var string $loginUrl */
?>
<h1>Sign in</h1>

<?php foreach ($messages as $message): ?>
    <p class="lf-notice lf-notice--<?= Html::e($message['type']) ?>" role="<?= in_array($message['type'], ['error', 'warning'], true) ? 'alert' : 'status' ?>"><?= Html::e($message['message']) ?></p>
<?php endforeach; ?>

<?php if ($error !== null): ?>
    <p class="lf-notice lf-notice--error" role="alert" id="lf-login-error"><?= Html::e($error) ?></p>
<?php endif; ?>

<form method="post" action="<?= Html::e($loginUrl) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-field">
        <label for="login_name">Login name or email address</label>
        <input type="text" id="login_name" name="login_name" value="<?= Html::e($identity) ?>" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false"<?= $error !== null ? ' aria-describedby="lf-login-error"' : '' ?>>
    </div>

    <div class="lf-field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password"<?= $error !== null ? ' aria-describedby="lf-login-error"' : '' ?>>
    </div>

    <p><button type="submit" class="lf-button">Sign in</button></p>
</form>
