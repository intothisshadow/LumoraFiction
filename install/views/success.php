<?php

/**
 * Installer screen shown once installation has finished.
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

use LumoraFiction\Installer\Html;

/** @var string $baseUrl */
$pageTitle = 'Installation complete';

require __DIR__ . '/_head.php';
?>
        <h1>Lumora Fiction is installed</h1>
        <p>Every story deserves a place. Yours is ready.</p>
        <!-- INSTALLER_CLEANUP_STATUS -->
        <p><a class="button" href="<?= Html::e($baseUrl) ?>/">Go to the site</a></p>
<?php require __DIR__ . '/_foot.php'; ?>
