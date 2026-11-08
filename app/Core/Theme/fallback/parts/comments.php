<?php

/**
 * The comments under a chapter: the count, the list, the notices and the form. Printed by lf_comments().
 *
 * @package LumoraFiction
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.2.0
 */

declare(strict_types=1);

$items = lf_items('comments.items');
// The form is a list of one so the part can hand it on whole; the form token and the spam-guard fields come with it.
$forms = lf_items('comments.forms');
$page = (int) lf_value('comments.pagination.page');
$pages = (int) lf_value('comments.pagination.pages');
$pageUrl = lf_value('comments.pagination.url');

?>
<section class="lf-comments" id="<?php lf_field('comments.anchor'); ?>" aria-labelledby="lf-comments-heading">
    <h2 class="lf-comments__heading" id="lf-comments-heading">Comments <span class="lf-comments__count">(<?php lf_field('comments.count_label'); ?>)</span></h2>

    <?php if (lf_has('comments.closed')): ?>
        <p class="lf-notice lf-notice--info lf-comments__closed" role="status"><?php lf_field('comments.closed_text'); ?></p>
    <?php endif; ?>

    <?php if ($items === []): ?>
        <p class="lf-comments__empty">No comments yet.</p>
    <?php else: ?>
        <ol class="lf-comments__list">
            <?php foreach ($items as $comment): ?>
                <?php lf_part('comment', ['comment' => $comment]); ?>
            <?php endforeach; ?>
        </ol>

        <?php if ($pages > 1): ?>
            <nav class="lf-comments__pagination" aria-label="Comment pages">
                <?php if ($page > 1): ?><a class="lf-comments__page-link" href="<?= lf_e(str_replace('{page}', (string) ($page - 1), $pageUrl)) ?>" rel="prev">Earlier comments</a><?php endif; ?>
                <span class="lf-comments__page-status">Page <?= (int) $page ?> of <?= (int) $pages ?></span>
                <?php if ($page < $pages): ?><a class="lf-comments__page-link" href="<?= lf_e(str_replace('{page}', (string) ($page + 1), $pageUrl)) ?>" rel="next">Later comments</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($forms !== []): ?>
        <div class="lf-comments__form">
            <?php foreach ($forms as $form): ?>
                <?php lf_part('account-form', ['form' => $form]); ?>
            <?php endforeach; ?>
        </div>
    <?php elseif (lf_has('comments.signin')): ?>
        <p class="lf-comments__signin"><?php lf_field('comments.signin.text'); ?> <a href="<?php lf_field('comments.signin.url'); ?>"><?php lf_field('comments.signin.label'); ?></a></p>
    <?php endif; ?>
</section>
