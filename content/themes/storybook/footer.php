<?php

/**
 * Storybook theme: the bottom of every page.
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
</main>

<footer class="lf-site-footer">
    <div class="lf-site-footer__inner">
        <p class="lf-site-footer__text"><?php lf_site_name(); ?><?php if (lf_option_enabled('show_credit')): ?> &middot; Powered by Lumora Fiction<?php endif; ?></p>
    </div>
</footer>
<?php lf_footer(); ?>
</body>
</html>
