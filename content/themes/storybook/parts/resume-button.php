<?php

/**
 * Storybook theme: the button that takes a signed-in reader back to where they stopped in this chapter.
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
<?php if (lf_has('resume.label')): ?>
    <?php // Hidden until the reading script confirms the saved place is further down than the first screen. ?>
    <p class="lf-resume" data-lf-resume hidden><button class="lf-button lf-resume__button" type="button"><?php lf_field('resume.label'); ?> <span class="lf-resume__percent">(<?php lf_field('resume.percent'); ?>%)</span></button></p>
<?php endif; ?>
