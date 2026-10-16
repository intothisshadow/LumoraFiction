<?php

/**
 * The page frame for admin screens that have no menu, such as sign-in.
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

/** @var string $title */
/** @var string $content trusted markup produced by another view, not user input */
/** @var string $siteName */
/** @var string $assetVersion */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= Html::e($title) ?> &lsaquo; <?= Html::e($siteName) ?></title>
    <script src="<?= Html::e(AdminUrl::asset('js/theme-init.js', $assetVersion)) ?>"></script>
    <link rel="stylesheet" href="<?= Html::e(AdminUrl::asset('css/admin.css', $assetVersion)) ?>">
</head>
<body class="lf-admin lf-admin--bare">
    <a class="lf-skip-link" href="#lf-main">Skip to content</a>
    <main id="lf-main" class="lf-bare" tabindex="-1">
        <p class="lf-bare__brand"><?= Html::e($siteName) ?></p>
        <?= $content ?>
    </main>
    <script src="<?= Html::e(AdminUrl::asset('js/admin.js', $assetVersion)) ?>" defer></script>
</body>
</html>
