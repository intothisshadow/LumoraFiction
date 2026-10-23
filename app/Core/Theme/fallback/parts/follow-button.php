<?php

/**
 * Built-in theme: the Follow this story / Stop following button on a story page.
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

$buttons = lf_items('follow');

?>
<?php if ($buttons !== []): ?>
    <div class="lf-follow">
        <?php foreach ($buttons as $button): ?>
            <?php lf_part('bookmark-form', ['form' => $button['form'] ?? []]); ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
