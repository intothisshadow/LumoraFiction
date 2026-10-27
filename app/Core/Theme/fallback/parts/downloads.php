<?php

/**
 * The download menu on a story page: a button that opens the formats the story can be taken away in.
 *
 * @package LumoraFiction
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.1.0
 */

declare(strict_types=1);

$formats = lf_items('downloads.formats');

?>
<?php if ($formats !== []): ?>
    <details class="lf-downloads">
        <summary class="lf-downloads__toggle">
            <svg class="lf-downloads__icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M12 3v12m0 0l-4.5-4.5M12 15l4.5-4.5M4 19h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="lf-downloads__label"><?php lf_field('downloads.label'); ?></span>
        </summary>
        <ul class="lf-downloads__list">
            <?php foreach ($formats as $format): ?>
                <li class="lf-downloads__item">
                    <a class="lf-downloads__link" href="<?= lf_e($format['url'] ?? '') ?>" rel="nofollow">
                        <span class="lf-downloads__format"><?= lf_e($format['label'] ?? '') ?></span>
                        <span class="lf-downloads__hint"><?= lf_e($format['description'] ?? '') ?></span>
                        <?php if (isset($format['count']) && $format['count'] !== null): ?>
                            <span class="lf-downloads__count"><?= lf_e(number_format((int) $format['count'])) ?> <?= (int) $format['count'] === 1 ? 'download' : 'downloads' ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </details>
<?php endif; ?>
