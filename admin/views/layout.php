<?php

/**
 * The admin page frame: header, menu, messages, and footer around a screen's content.
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
use LumoraFiction\Models\User;

/** @var string $title */
/** @var string $content trusted markup produced by another view, not user input */
/** @var User $user */
/** @var list<array{label: string, url: string, current: bool, open: bool, children: list<array{label: string, url: string, current: bool}>}> $menu */
/** @var list<array{type: string, message: string}> $flash */
/** @var string $siteName */
/** @var string $version */
/** @var string $signOutToken */
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
<body class="lf-admin">
    <a class="lf-skip-link" href="#lf-main">Skip to content</a>

    <header class="lf-admin__header">
        <p class="lf-admin__brand"><a href="<?= Html::e(AdminUrl::to()) ?>"><?= Html::e($siteName) ?></a> <span class="lf-admin__version">Lumora Fiction <?= Html::e($version) ?></span></p>

        <div class="lf-admin__tools">
            <a class="lf-button lf-button--quiet lf-admin__site-link" href="<?= Html::e(AdminUrl::site()) ?>">View site</a>
            <a class="lf-button lf-button--quiet lf-admin__account-link" href="<?= Html::e(AdminUrl::account()) ?>">My account</a>
            <button type="button" class="lf-button lf-button--quiet" data-lf-theme-toggle aria-pressed="false" hidden>Dark mode</button>

            <form class="lf-admin__signout" method="post" action="<?= Html::e(AdminUrl::to('logout')) ?>">
                <input type="hidden" name="csrf_token" value="<?= Html::e($signOutToken) ?>">
                <span class="lf-admin__user">Signed in as <strong><?= Html::e($user->displayName) ?></strong> (<?= Html::e($user->role->label()) ?>)</span>
                <button type="submit" class="lf-button lf-button--quiet">Sign out</button>
            </form>
        </div>
    </header>

    <div class="lf-admin__body">
        <nav class="lf-admin__nav" aria-label="Admin menu">
            <ul class="lf-menu">
                <?php foreach ($menu as $item): ?>
                    <li class="lf-menu__item<?= $item['open'] ? ' is-open' : '' ?>">
                        <a href="<?= Html::e($item['url']) ?>"<?= $item['current'] ? ' aria-current="page"' : '' ?>><?= Html::e($item['label']) ?></a>
                        <?php if ($item['children'] !== []): ?>
                            <ul class="lf-menu__children">
                                <?php foreach ($item['children'] as $child): ?>
                                    <li><a href="<?= Html::e($child['url']) ?>"<?= $child['current'] ? ' aria-current="page"' : '' ?>><?= Html::e($child['label']) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <main id="lf-main" class="lf-admin__main" tabindex="-1">
            <h1><?= Html::e($title) ?></h1>

            <?php foreach ($flash as $message): ?>
                <p class="lf-notice lf-notice--<?= Html::e($message['type']) ?>" role="<?= in_array($message['type'], ['error', 'warning'], true) ? 'alert' : 'status' ?>"><?= Html::e($message['message']) ?></p>
            <?php endforeach; ?>

            <?= $content ?>
        </main>
    </div>

    <footer class="lf-admin__footer">
        <p>Lumora Fiction <?= Html::e($version) ?></p>
    </footer>

    <script src="<?= Html::e(AdminUrl::asset('js/admin.js', $assetVersion)) ?>" defer></script>
</body>
</html>
