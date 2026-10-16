<?php

/**
 * Shown to someone who is signed in but whose role has no access to the admin area.
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

/** @var string $signOutToken */
?>
<h1>Access denied</h1>
<p class="lf-notice lf-notice--error" role="alert">Your account does not have access to the admin area.</p>

<form method="post" action="<?= Html::e(AdminUrl::to('logout')) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($signOutToken) ?>">
    <p><button type="submit" class="lf-button">Sign out</button></p>
</form>
