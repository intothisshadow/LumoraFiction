<?php

/**
 * Storybook theme: the top of every page.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php lf_head(); ?>
</head>
<body class="<?php lf_body_class(); ?>">
<?php lf_body_open(); ?>
<a class="lf-skip-link" href="#lf-main">Skip to content</a>

<div class="lf-site-header-wrap">
    <header class="lf-site-header">
        <p class="lf-site-title"><a href="<?php lf_site_url(); ?>"><?php lf_site_name(); ?></a></p>
        <div class="lf-site-header__tools">
            <?php lf_navigation(); ?>
            <?php lf_part('scheme-switch'); ?>
        </div>
    </header>
</div>

<main id="lf-main" class="lf-main" tabindex="-1">
