<?php

/**
 * Built-in theme: the search page, with its form, the filters in use, and the results.
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
    <h1 class="lf-page__title"><?php lf_title(); ?></h1>

    <?php foreach (lf_items('notices') as $notice): ?>
        <p class="lf-notice lf-notice--<?= lf_e($notice['type'] ?? 'info') ?>" role="status"><?= lf_e($notice['text'] ?? '') ?></p>
    <?php endforeach; ?>

    <?php foreach (lf_items('forms') as $form): ?>
        <form class="lf-form lf-search-form" method="get" action="<?= lf_e($form['action'] ?? '') ?>" role="search">
            <?php foreach (is_array($form['fields'] ?? null) ? $form['fields'] : [] as $field): ?>
                <div class="lf-search-form__field">
                    <label class="lf-form__label" for="<?= lf_e($field['id'] ?? '') ?>"><?= lf_e($field['label'] ?? '') ?></label>
                    <?php if (($field['type'] ?? '') === 'select'): ?>
                        <select class="lf-form__input" id="<?= lf_e($field['id'] ?? '') ?>" name="<?= lf_e($field['name'] ?? '') ?>">
                            <?php foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option): ?>
                                <option value="<?= lf_e($option['value'] ?? '') ?>"<?= ($option['selected'] ?? false) === true ? ' selected' : '' ?>><?= lf_e($option['label'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input class="lf-form__input" id="<?= lf_e($field['id'] ?? '') ?>" name="<?= lf_e($field['name'] ?? '') ?>" type="<?= lf_e($field['type'] ?? 'text') ?>" value="<?= lf_e($field['value'] ?? '') ?>" autocomplete="off">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <p class="lf-form__actions">
                <button class="lf-button" type="submit"><?= lf_e($form['submit'] ?? 'Search') ?></button>
                <?php if (($form['filtered'] ?? false) === true): ?>
                    <a href="<?= lf_e($form['reset_url'] ?? '') ?>">Clear search</a>
                <?php endif; ?>
            </p>
        </form>
    <?php endforeach; ?>

    <?php $filters = lf_items('filters'); ?>
    <?php if ($filters !== []): ?>
        <ul class="lf-filters__list">
            <?php foreach ($filters as $filter): ?>
                <li class="lf-filters__item"><a href="<?= lf_e($filter['remove_url'] ?? '') ?>"><?= lf_e($filter['label'] ?? '') ?> (remove)</a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if (lf_has('stories')): ?>
        <?php if (lf_has('total')): ?>
            <p class="lf-archive__count">Stories found: <?php lf_field('total'); ?></p>
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
    <?php elseif (lf_has('searched') && !lf_has('notices')): ?>
        <p class="lf-page__message">No stories match that search. Try fewer words or remove a filter.</p>
    <?php endif; ?>
</section>
<?php
lf_get_footer();
