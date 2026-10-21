<?php

/**
 * Storybook theme: one story in a list, from a "story" array with title, url, author, author_url, summary,
 * status, words, updated and cover (thumb, full, alt). Any missing key is left out.
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

$story = is_array($story ?? null) ? $story : [];
$meta = array_filter([
    (string) ($story['status'] ?? ''),
    ($story['words'] ?? '') !== '' ? $story['words'] . ' words' : '',
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
        <?php if (($story['summary'] ?? '') !== ''): ?>
            <p class="lf-story-card__summary"><?= lf_e($story['summary']) ?></p>
        <?php endif; ?>
    </div>
</article>
