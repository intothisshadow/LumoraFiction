<?php

/**
 * Built-in theme: the page shown when an address does not lead anywhere.
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

lf_get_header();
?>
<article class="lf-page">
    <h1 class="lf-page__title"><?php lf_title(); ?></h1>
    <p class="lf-page__message"><?php lf_field('message'); ?></p>
    <p><a href="<?php lf_site_url(); ?>">Go to the home page</a></p>
</article>
<?php
lf_get_footer();
