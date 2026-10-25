<?php

/**
 * Storybook theme: one news post as a card, for the home page block and the News page.
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

$post = is_array($post ?? null) ? $post : [];
$by = array_filter([
    (string) ($post['pinned'] ?? ''),
    (string) ($post['author'] ?? '') !== '' ? 'by ' . $post['author'] : '',
], static fn (string $part): bool => $part !== '');

?>
<article class="lf-news-card<?= ($post['pinned'] ?? '') !== '' ? ' lf-news-card--pinned' : '' ?>">
    <h3 class="lf-news-card__title"><a href="<?= lf_e($post['url'] ?? '') ?>"><?= lf_e($post['title'] ?? '') ?></a></h3>
    <p class="lf-news-card__meta"><time datetime="<?= lf_e($post['datetime'] ?? '') ?>"><?= lf_e($post['date'] ?? '') ?></time><?php if ($by !== []): ?> &middot; <?= lf_e(implode(' · ', $by)) ?><?php endif; ?></p>
    <?php if (($post['excerpt'] ?? '') !== ''): ?>
        <p class="lf-news-card__excerpt"><?= lf_e($post['excerpt']) ?></p>
    <?php endif; ?>
</article>
