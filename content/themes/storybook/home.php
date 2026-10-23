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
    <h1 class="lf-screen-reader-text"><?php lf_site_name(); ?></h1>
    <?php if (!lf_has('stories')): ?>
        <div class="lf-home__intro">
            <p class="lf-page__message">Welcome. There are no stories to show yet.</p>
        </div>
    <?php endif; ?>

    <?php if (lf_has('stories')): ?>
        <section class="lf-home__stories" aria-labelledby="lf-home-stories-title">
            <h2 class="lf-section-title" id="lf-home-stories-title">Recently published</h2>
            <ol class="lf-story-list">
                <?php foreach (lf_items('stories') as $card): ?>
                    <li class="lf-story-list__item"><?php lf_part('story-card', ['story' => $card]); ?></li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>

    <?php if (lf_has('updated_stories')): ?>
        <section class="lf-home__stories lf-home__stories--updated" aria-labelledby="lf-home-updated-title">
            <h2 class="lf-section-title" id="lf-home-updated-title">Recently updated</h2>
            <ol class="lf-story-list">
                <?php foreach (lf_items('updated_stories') as $card): ?>
                    <li class="lf-story-list__item"><?php lf_part('story-card', ['story' => $card]); ?></li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>
</section>
<?php
lf_get_footer();
