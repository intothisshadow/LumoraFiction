<?php

/**
 * Lavender Mist theme: one news post as a card, for the home page block and the News page.
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

$post = is_array($post ?? null) ? $post : [];
$image = is_array($post['image'] ?? null) ? $post['image'] : [];
$by = array_filter([
    (string) ($post['pinned'] ?? ''),
    (string) ($post['author'] ?? '') !== '' ? 'by ' . $post['author'] : '',
], static fn (string $part): bool => $part !== '');

?>
<article class="lf-news-card<?= ($post['pinned'] ?? '') !== '' ? ' lf-news-card--pinned' : '' ?>">
    <?php if (($image['thumb'] ?? '') !== ''): ?>
        <p class="lf-news-image lf-news-image--card"><img class="lf-news-image__img" src="<?= lf_e($image['thumb']) ?>" width="<?= lf_e($image['thumb_width'] ?? 0) ?>" height="<?= lf_e($image['thumb_height'] ?? 0) ?>" alt="<?= lf_e($image['alt'] ?? '') ?>" loading="lazy"></p>
    <?php endif; ?>
    <h3 class="lf-news-card__title"><a href="<?= lf_e($post['url'] ?? '') ?>"><?= lf_e($post['title'] ?? '') ?></a></h3>
    <p class="lf-news-card__meta"><time datetime="<?= lf_e($post['datetime'] ?? '') ?>"><?= lf_e($post['date'] ?? '') ?></time><?php if ($by !== []): ?> &middot; <?= lf_e(implode(' · ', $by)) ?><?php endif; ?></p>
    <?php if (($post['excerpt'] ?? '') !== ''): ?>
        <p class="lf-news-card__excerpt"><?= lf_e($post['excerpt']) ?></p>
    <?php endif; ?>
</article>
