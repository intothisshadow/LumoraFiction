<?php

/**
 * Classic Archive theme: the top of every page.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
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
<?php lf_theme_preview_banner(); ?>

<div class="lf-site-header-wrap">
    <header class="lf-site-header">
        <p class="lf-site-title"><a href="<?php lf_site_url(); ?>"><?php lf_site_name(); ?></a></p>
        <div class="lf-site-header__tools">
            <?php lf_navigation('Account', 'account'); ?>
            <?php lf_part('scheme-switch'); ?>
            <?php lf_theme_switch(); ?>
        </div>
    </header>
</div>
<?php if (lf_option_enabled('show_header_image')): ?>
    <?php // Decorative only: the picture is a background chosen in the theme's options, so it carries no text alternative. ?>
    <div class="lf-site-banner lf-site-banner--<?php lf_option('header_image'); ?>" aria-hidden="true"></div>
<?php endif; ?>
<div class="lf-site-nav-wrap">
    <div class="lf-site-nav">
        <?php lf_navigation('Main', 'main'); ?>
    </div>
</div>

<main id="lf-main" class="lf-main" tabindex="-1">
