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

    <?php foreach (lf_items('related') as $group): ?>
        <section class="lf-related">
            <h2 class="lf-related__heading"><?= lf_e($group['label'] ?? '') ?> in this fandom</h2>
            <ul class="lf-related__list">
                <?php foreach (is_array($group['terms'] ?? null) ? $group['terms'] : [] as $term): ?>
                    <li class="lf-related__item"><a class="lf-related__link" href="<?= lf_e($term['url'] ?? '') ?>"><?= lf_e($term['name'] ?? '') ?></a> <span class="lf-related__count"><?= lf_e($term['count_label'] ?? '') ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>

    <?php if (lf_has('stories')): ?>
        <div class="lf-archive__toolbar">
            <?php if (lf_has('total')): ?>
                <p class="lf-archive__count">Stories: <?php lf_field('total'); ?></p>
            <?php endif; ?>
            <?php if (lf_has('sorts')): ?>
                <nav class="lf-sort" aria-label="Sort stories">
                    <span class="lf-sort__label">Sort by</span>
                    <ul class="lf-sort__list">
                        <?php foreach (lf_items('sorts') as $sort): ?>
                            <li class="lf-sort__item<?= lf_e(($sort['current'] ?? false) === true ? ' lf-sort__item--current' : '') ?>"><a class="lf-sort__link" href="<?= lf_e($sort['url'] ?? '') ?>"<?= ($sort['current'] ?? false) === true ? ' aria-current="true"' : '' ?>><?= lf_e($sort['label'] ?? '') ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
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
