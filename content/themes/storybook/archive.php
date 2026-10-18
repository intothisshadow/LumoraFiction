<?php

/**
 * Storybook theme: a list of stories, used for author pages, term listings and search results.
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
<section class="lf-archive">
    <header class="lf-archive__header">
        <h1 class="lf-archive__title"><?php lf_title(); ?></h1>
        <?php if (lf_has('description')): ?>
            <p class="lf-archive__description"><?php lf_field('description'); ?></p>
        <?php endif; ?>
    </header>

    <?php if (lf_has('stories')): ?>
        <ol class="lf-story-list">
            <?php foreach (lf_items('stories') as $card): ?>
                <li class="lf-story-list__item"><?php lf_part('story-card', ['story' => $card]); ?></li>
            <?php endforeach; ?>
        </ol>
        <?php lf_pagination(); ?>
    <?php else: ?>
        <p class="lf-page__message">There are no stories here yet.</p>
    <?php endif; ?>
</section>
<?php
lf_get_footer();
