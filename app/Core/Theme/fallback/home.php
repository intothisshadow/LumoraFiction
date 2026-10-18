<?php

/**
 * Built-in theme: the home page.
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
<section class="lf-home">
    <h1 class="lf-page__title"><?php lf_site_name(); ?></h1>
    <p class="lf-page__message">Welcome. There are no stories to show yet.</p>
</section>
<?php
lf_get_footer();
