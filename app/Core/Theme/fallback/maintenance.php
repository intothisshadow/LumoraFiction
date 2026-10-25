<?php

/**
 * Built-in theme: the page visitors see while the site is in maintenance mode.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

lf_get_header();
?>
<article class="lf-page">
    <h1 class="lf-page__title"><?php lf_title(); ?></h1>
    <p class="lf-page__message"><?php lf_field('message'); ?></p>
</article>
<?php
lf_get_footer();
