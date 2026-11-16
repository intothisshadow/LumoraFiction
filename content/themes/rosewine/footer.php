<?php

/**
 * Rosewine theme: the bottom of every page.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

?>
</main>

<footer class="lf-site-footer">
    <div class="lf-site-footer__inner">
        <?php lf_footer_links(); ?>
        <?php lf_footer_text(); ?>
        <p class="lf-site-footer__text"><?php lf_site_name(); ?><?php if (lf_option_enabled('show_credit')): ?> &middot; Powered by <a href="https://coding.unloved-heart.net/scripts/lumorafiction">Lumora Fiction</a><?php endif; ?></p>
    </div>
</footer>
<?php lf_footer(); ?>
</body>
</html>
