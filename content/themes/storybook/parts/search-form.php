<?php

/**
 * Storybook theme: the search form. The search words come first; the filters sit in a disclosure that opens by
 * itself while a filter is in use, and the form works as a plain GET form without scripts.
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

$form = is_array($form ?? null) ? $form : [];
$fields = is_array($form['fields'] ?? null) ? $form['fields'] : [];

$main = array_values(array_filter($fields, static fn (array $field): bool => ($field['name'] ?? '') === 'q'));
$more = array_values(array_filter($fields, static fn (array $field): bool => ($field['name'] ?? '') !== 'q'));

?>
<form class="lf-form lf-search-form" method="get" action="<?= lf_e($form['action'] ?? '') ?>" role="search">
    <?php foreach ($main as $field): ?>
        <?php lf_part('search-field', ['field' => $field]); ?>
    <?php endforeach; ?>
    <?php if ($more !== []): ?>
        <details class="lf-search-form__more"<?= ($form['filtered'] ?? false) === true ? ' open' : '' ?>>
            <summary class="lf-search-form__summary">Filters and sorting</summary>
            <div class="lf-search-form__grid">
                <?php foreach ($more as $field): ?>
                    <?php lf_part('search-field', ['field' => $field]); ?>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>
    <p class="lf-form__actions">
        <button class="lf-button" type="submit"><?= lf_e($form['submit'] ?? 'Search') ?></button>
        <?php if (($form['filtered'] ?? false) === true): ?>
            <a class="lf-search-form__reset" href="<?= lf_e($form['reset_url'] ?? '') ?>">Clear search</a>
        <?php endif; ?>
    </p>
</form>
