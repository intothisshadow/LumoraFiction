<?php

/**
 * Storybook theme: the search page, with its form, the filters in use, and the results.
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
<section class="lf-archive lf-search">
    <header class="lf-archive__header">
        <h1 class="lf-archive__title"><?php lf_title(); ?></h1>
    </header>

    <?php foreach (lf_items('notices') as $notice): ?>
        <p class="lf-notice lf-notice--<?= lf_e($notice['type'] ?? 'info') ?>" role="status"><?= lf_e($notice['text'] ?? '') ?></p>
    <?php endforeach; ?>
    <?php foreach (lf_items('forms') as $form): ?>
        <?php lf_part('search-form', ['form' => $form]); ?>
    <?php endforeach; ?>

    <?php $filters = lf_items('filters'); ?>
    <?php if ($filters !== []): ?>
        <nav class="lf-filters" aria-label="Search filters in use">
            <ul class="lf-filters__list">
                <?php foreach ($filters as $filter): ?>
                    <li class="lf-filters__item"><a class="lf-filters__link" href="<?= lf_e($filter['remove_url'] ?? '') ?>"><?= lf_e($filter['label'] ?? '') ?> <span class="lf-filters__remove" aria-hidden="true">&times;</span><span class="lf-screen-reader-text">Remove this filter</span></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>

    <?php if (lf_has('stories')): ?>
        <?php if (lf_has('total')): ?>
            <p class="lf-archive__count" role="status">Stories found: <?php lf_field('total'); ?></p>
        <?php endif; ?>
        <ol class="lf-story-list">
            <?php foreach (lf_items('stories') as $card): ?>
                <li class="lf-story-list__item"><?php lf_part('story-card', ['story' => $card]); ?></li>
            <?php endforeach; ?>
        </ol>
        <?php lf_pagination(); ?>
    <?php elseif (lf_has('searched') && !lf_has('notices')): ?>
        <p class="lf-page__message" role="status">No stories match that search. Try fewer words or remove a filter.</p>
    <?php elseif (!lf_has('notices')): ?>
        <p class="lf-page__message">There are no stories here yet.</p>
    <?php endif; ?>
</section>
<?php
lf_get_footer();
