<?php

/**
 * Built-in theme: messages about what the reader just did, such as a saved bookmark.
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

?>
<?php foreach (lf_items('notices') as $notice): ?>
    <p class="lf-notice lf-notice--<?= lf_e($notice['type'] ?? 'info') ?>" role="<?= ($notice['type'] ?? '') === 'error' ? 'alert' : 'status' ?>"><?= lf_e($notice['text'] ?? '') ?></p>
<?php endforeach; ?>
