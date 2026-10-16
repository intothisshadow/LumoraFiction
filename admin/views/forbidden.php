<?php

/**
 * Shown inside the admin layout when someone opens a screen their role does not allow.
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

?>
<p class="lf-notice lf-notice--error" role="alert">Your account is not allowed to open this page.</p>
<p><a class="lf-button" href="<?= Html::e(AdminUrl::to()) ?>">Back to the dashboard</a></p>
