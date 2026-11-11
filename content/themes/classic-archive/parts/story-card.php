<?php

/**
 * Classic Archive theme: one story in a list, from a "story" array with title, url, author, author_url, summary,
 * status, words, chapters, term_groups, updated and cover (thumb, full, alt). Any missing key is left out.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

$story = is_array($story ?? null) ? $story : [];
$groups = is_array($story['term_groups'] ?? null) ? $story['term_groups'] : [];
$status = (string) ($story['status'] ?? '');

// Rating and fandom are drawn apart from the other terms; every other group becomes one tag list, in the order given.
$ratingName = '';
$fandoms = [];
$tagGroups = [];
foreach ($groups as $group) {
    $type = (string) ($group['type'] ?? '');
    if ($type === 'rating') {
        $ratingName = (string) ($group['terms'][0]['name'] ?? '');
    } elseif ($type === 'fandom') {
        $fandoms[] = $group;
    } else {
        $tagGroups[] = $group;
    }
}

// The square carries the first letter only; the full name is in the title and in text for screen readers.
$ratingLetter = $ratingName !== '' ? mb_strtoupper(mb_substr($ratingName, 0, 1)) : '';
$statusSlug = $status !== '' ? trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($status)), '-') : '';
$stats = [
    'Words' => (string) ($story['words'] ?? ''),
    // The presenter words it as "2 chapters"; the label already says so.
    'Chapters' => trim((string) preg_replace('/\s+chapters?$/i', '', (string) ($story['chapters'] ?? ''))),
    'Updated' => (string) ($story['updated'] ?? ''),
];

?>
<article class="lf-story-card">
    <div class="lf-story-card__signals">
        <?php if ($ratingLetter !== ''): ?>
            <span class="lf-signal lf-signal--rating" title="<?= lf_e($ratingName) ?>"><span aria-hidden="true"><?= lf_e($ratingLetter) ?></span><span class="lf-screen-reader-text">Rating: <?= lf_e($ratingName) ?></span></span>
        <?php endif; ?>
        <?php if ($status !== ''): ?>
            <span class="lf-signal lf-signal--status lf-signal--status-<?= lf_e($statusSlug) ?>" title="<?= lf_e($status) ?>"><span aria-hidden="true"><?= $statusSlug === 'completed' ? '&#10003;' : lf_e(mb_strtoupper(mb_substr($status, 0, 1))) ?></span><span class="lf-screen-reader-text">Status: <?= lf_e($status) ?></span></span>
        <?php endif; ?>
        <?php if (($story['cover']['thumb'] ?? '') !== ''): ?>
            <?php // The picture repeats the title link beside it, so it is hidden from keyboards and screen readers here; the story page describes it. ?>
            <a class="lf-story-card__cover" href="<?= lf_e($story['url'] ?? '') ?>" tabindex="-1" aria-hidden="true">
                <img class="lf-cover lf-cover--thumb" src="<?= lf_e($story['cover']['thumb']) ?>" alt="" loading="lazy" decoding="async">
            </a>
        <?php endif; ?>
    </div>
    <div class="lf-story-card__body">
        <h3 class="lf-story-card__title"><a href="<?= lf_e($story['url'] ?? '') ?>"><?= lf_e($story['title'] ?? '') ?></a></h3>
        <?php if (($story['author'] ?? '') !== ''): ?>
            <p class="lf-story-card__byline">by <?php if (($story['author_url'] ?? '') !== ''): ?><a href="<?= lf_e($story['author_url']) ?>"><?= lf_e($story['author']) ?></a><?php else: ?><?= lf_e($story['author']) ?><?php endif; ?></p>
        <?php endif; ?>
        <?php foreach ($fandoms as $group): ?>
            <p class="lf-story-card__fandoms"><?php foreach (is_array($group['terms'] ?? null) ? $group['terms'] : [] as $index => $term): ?><?= $index > 0 ? ', ' : '' ?><?php if (($term['url'] ?? '') !== ''): ?><a class="lf-story-card__term" href="<?= lf_e($term['url']) ?>"><?= lf_e($term['name'] ?? '') ?></a><?php else: ?><span class="lf-story-card__term"><?= lf_e($term['name'] ?? '') ?></span><?php endif; ?><?php endforeach; ?><?php if ((int) ($group['more'] ?? 0) > 0): ?> <span class="lf-story-card__term-more">and <?= (int) $group['more'] ?> more</span><?php endif; ?></p>
        <?php endforeach; ?>
        <?php if ($tagGroups !== []): ?>
            <ul class="lf-story-card__tags">
                <?php foreach ($tagGroups as $group): ?>
                    <?php foreach (is_array($group['terms'] ?? null) ? $group['terms'] : [] as $term): ?>
                        <li class="lf-tag lf-tag--<?= lf_e($group['type'] ?? '') ?>" title="<?= lf_e($group['label'] ?? '') ?>"><?php if (($term['url'] ?? '') !== ''): ?><a class="lf-tag__link" href="<?= lf_e($term['url']) ?>"><?= lf_e($term['name'] ?? '') ?></a><?php else: ?><span class="lf-tag__link"><?= lf_e($term['name'] ?? '') ?></span><?php endif; ?></li>
                    <?php endforeach; ?>
                    <?php if ((int) ($group['more'] ?? 0) > 0): ?><li class="lf-tag lf-tag--more">and <?= (int) $group['more'] ?> more</li><?php endif; ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (($story['summary'] ?? '') !== ''): ?>
            <p class="lf-story-card__summary"><?= lf_e($story['summary']) ?></p>
        <?php endif; ?>
        <?php if (array_filter($stats, static fn (string $value): bool => $value !== '') !== []): ?>
            <dl class="lf-story-card__stats">
                <?php foreach ($stats as $label => $value): ?>
                    <?php if ($value !== ''): ?>
                        <div class="lf-story-card__stat"><dt><?= lf_e($label) ?>:</dt> <dd><?= lf_e($value) ?></dd></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
    </div>
</article>
