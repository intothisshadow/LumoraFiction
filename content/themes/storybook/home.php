<?php

/**
 * Storybook theme: the home page, with the stories handed to it as a "stories" list when there are any.
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
    <div class="lf-home__intro">
        <h1 class="lf-home__title"><?php lf_site_name(); ?></h1>
        <?php if (!lf_has('stories')): ?>
            <p class="lf-page__message">Welcome. There are no stories to show yet.</p>
        <?php endif; ?>
    </div>

    <?php if (lf_has('stories')): ?>
        <section class="lf-home__stories" aria-labelledby="lf-home-stories-title">
            <h2 class="lf-section-title" id="lf-home-stories-title">Recent stories</h2>
            <ol class="lf-story-list">
                <?php foreach (lf_items('stories') as $card): ?>
                    <li class="lf-story-list__item"><?php lf_part('story-card', ['story' => $card]); ?></li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>
</section>
<?php
lf_get_footer();
