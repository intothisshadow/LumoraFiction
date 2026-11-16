<?php

/**
 * Lavender Mist theme: one story in a list, from a "story" array with title, url, author, author_url, summary,
 * status, words, chapters, term_groups, updated and cover (thumb, full, alt). Any missing key is left out.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

$story = is_array($story ?? null) ? $story : [];
$meta = array_filter([
    (string) ($story['status'] ?? ''),
    ($story['words'] ?? '') !== '' ? $story['words'] . ' words' : '',
    (string) ($story['chapters'] ?? ''),
    ($story['updated'] ?? '') !== '' ? 'Updated ' . $story['updated'] : '',
], static fn (string $part): bool => $part !== '');

?>
<article class="lf-story-card">
    <?php // The picture repeats the title link beside it, so it is hidden from keyboards and screen readers here; the story page describes it. ?>
    <a class="lf-story-card__cover" href="<?= lf_e($story['url'] ?? '') ?>" tabindex="-1" aria-hidden="true">
        <?php if (($story['cover']['thumb'] ?? '') !== ''): ?>
            <img class="lf-cover lf-cover--thumb" src="<?= lf_e($story['cover']['thumb']) ?>" alt="" loading="lazy" decoding="async">
        <?php else: ?>
            <span class="lf-cover lf-cover--placeholder"></span>
        <?php endif; ?>
    </a>
    <div class="lf-story-card__body">
        <h3 class="lf-story-card__title"><a href="<?= lf_e($story['url'] ?? '') ?>"><?= lf_e($story['title'] ?? '') ?></a></h3>
        <?php if (($story['author'] ?? '') !== ''): ?>
            <p class="lf-story-card__byline">by <?php if (($story['author_url'] ?? '') !== ''): ?><a href="<?= lf_e($story['author_url']) ?>"><?= lf_e($story['author']) ?></a><?php else: ?><?= lf_e($story['author']) ?><?php endif; ?></p>
        <?php endif; ?>
        <?php if ($meta !== []): ?>
            <p class="lf-story-card__meta"><?= lf_e(implode(' · ', $meta)) ?></p>
        <?php endif; ?>
        <?php if (is_array($story['term_groups'] ?? null) && $story['term_groups'] !== []): ?>
            <dl class="lf-story-card__terms">
                <?php foreach ($story['term_groups'] as $group): ?>
                    <div class="lf-story-card__term-group lf-story-card__term-group--<?= lf_e($group['type'] ?? '') ?>">
                        <dt class="lf-story-card__term-label"><?= lf_e($group['label'] ?? '') ?></dt>
                        <dd class="lf-story-card__term-list">
                            <?php foreach (is_array($group['terms'] ?? null) ? $group['terms'] : [] as $term): ?><?php if (($term['url'] ?? '') !== ''): ?><a class="lf-term-link lf-story-card__term" href="<?= lf_e($term['url']) ?>"><?= lf_e($term['name'] ?? '') ?></a><?php else: ?><span class="lf-term-link lf-story-card__term"><?= lf_e($term['name'] ?? '') ?></span><?php endif; ?><?php endforeach; ?>
                            <?php if ((int) ($group['more'] ?? 0) > 0): ?><span class="lf-story-card__term-more">and <?= (int) $group['more'] ?> more</span><?php endif; ?>
                        </dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
        <?php if (($story['summary'] ?? '') !== ''): ?>
            <p class="lf-story-card__summary"><?= lf_e($story['summary']) ?></p>
        <?php endif; ?>
    </div>
</article>
