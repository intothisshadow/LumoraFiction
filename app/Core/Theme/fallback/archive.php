<?php

/**
 * Built-in theme: a list of stories, used for author pages and term listings.
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
    <?php if (lf_has('author_about') || lf_has('author_avatar')): ?>
        <p class="lf-author-about__name"><?php lf_field('author_name'); ?></p>
        <section class="lf-author-about" aria-labelledby="lf-author-about-heading">
            <?php if (lf_has('author_avatar')): ?>
                <img class="lf-author-about__avatar" src="<?php lf_field('author_avatar'); ?>" width="100" height="100" alt="">
            <?php endif; ?>
            <div class="lf-author-about__body">
                <h2 class="lf-author-about__heading" id="lf-author-about-heading">About the author</h2>
                <?php if (lf_has('author_about')): ?>
                    <div class="lf-author-about__text"><?php lf_field('author_about'); ?></div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
    <h1 class="lf-page__title"><?php lf_title(); ?></h1>
    <?php foreach (lf_items('related') as $group): ?>
        <h2 class="lf-related__heading"><?= lf_e($group['label'] ?? '') ?> in this fandom</h2>
        <ul class="lf-related__list">
            <?php foreach (is_array($group['terms'] ?? null) ? $group['terms'] : [] as $term): ?>
                <li class="lf-related__item"><a class="lf-related__link" href="<?= lf_e($term['url'] ?? '') ?>"><?= lf_e($term['name'] ?? '') ?></a> <span class="lf-related__count"><?= lf_e($term['count_label'] ?? '') ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>

    <?php if (lf_has('stories')): ?>
        <?php if (lf_has('sorts')): ?>
            <p class="lf-archive__sort">Sort by:
                <?php foreach (lf_items('sorts') as $sort): ?>
                    <a href="<?= lf_e($sort['url'] ?? '') ?>"<?= ($sort['current'] ?? false) === true ? ' aria-current="true"' : '' ?>><?= lf_e($sort['label'] ?? '') ?></a>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
        <ol class="lf-story-list">
            <?php foreach (lf_items('stories') as $card): ?>
                <li class="lf-story-list__item">
                    <a class="lf-story-list__link" href="<?= lf_e($card['url'] ?? '') ?>"><?= lf_e($card['title'] ?? '') ?></a>
                    <span class="lf-story-list__meta">by <?= lf_e($card['author'] ?? '') ?> &middot; <?= lf_e($card['words'] ?? '') ?> words</span>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php lf_pagination(); ?>
    <?php else: ?>
        <p class="lf-page__message">There are no stories here yet.</p>
    <?php endif; ?>
</section>
<?php
lf_get_footer();
