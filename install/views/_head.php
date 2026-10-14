<?php

/**
 * Shared page opening for every installer screen.
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

/** @var string $pageTitle */
/** @var string $installUrl */
/** @var string $baseUrl */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= Html::e($pageTitle) ?> &ndash; Lumora Fiction</title>
    <link rel="stylesheet" href="<?= Html::e($installUrl) ?>assets/install.css">
</head>
<body>
    <a class="skip-link" href="#main">Skip to the installer</a>
    <main id="main" class="installer">
        <p class="brand">Lumora Fiction</p>
