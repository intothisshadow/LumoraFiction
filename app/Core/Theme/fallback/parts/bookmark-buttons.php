<?php

/**
 * Built-in theme: the bookmark buttons for a story or chapter page. Nothing is drawn for a guest.
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

$buttons = lf_items('bookmarks');

?>
<?php if ($buttons !== []): ?>
    <div class="lf-bookmarks" role="group" aria-label="Bookmarks">
        <?php foreach ($buttons as $button): ?>
            <?php lf_part('bookmark-form', ['form' => $button['form'] ?? []]); ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
