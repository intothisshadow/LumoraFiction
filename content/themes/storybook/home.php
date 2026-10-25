<?php

/**
 * Storybook theme: the home page, with the stories in a wide column and the news in a narrower one beside it.
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
<section class="lf-home<?= lf_has('news.posts') ? '' : ' lf-home--single' ?>">
    <h1 class="lf-screen-reader-text"><?php lf_site_name(); ?></h1>
    <?php if (!lf_has('stories')): ?>
        <div class="lf-home__intro">
            <p class="lf-page__message">Welcome. There are no stories to show yet.</p>
        </div>
    <?php endif; ?>

    <?php // The stories come first in the source so a narrow screen, which stacks the columns, reads them before the news. ?>
    <div class="lf-home__columns">
        <div class="lf-home__main">
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
        </div>

        <?php if (lf_has('news.posts')): ?>
            <aside class="lf-home__aside" aria-labelledby="lf-home-news-title">
                <section class="lf-home__news">
                    <h2 class="lf-section-title" id="lf-home-news-title">News</h2>
                    <ol class="lf-news-list">
                        <?php foreach (lf_items('news.posts') as $card): ?>
                            <li class="lf-news-list__item"><?php lf_part('news-card', ['post' => $card]); ?></li>
                        <?php endforeach; ?>
                    </ol>
                    <p class="lf-home__more"><a href="<?php lf_field('news.url'); ?>">All news</a></p>
                </section>
            </aside>
        <?php endif; ?>
    </div>
</section>
<?php
lf_get_footer();
