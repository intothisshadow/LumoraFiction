<?php

/**
 * Installer screen shown when the site is already installed.
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
$pageTitle = 'Already installed';

require __DIR__ . '/_head.php';
?>
        <h1>Already installed</h1>
        <p>Lumora Fiction is already installed on this site.</p>
        <p class="notice notice--error" role="alert">For security, delete the <code>install</code> folder from the server.</p>
        <p><a class="button" href="<?= Html::e($baseUrl) ?>/">Go to the site</a></p>
<?php require __DIR__ . '/_foot.php'; ?>
