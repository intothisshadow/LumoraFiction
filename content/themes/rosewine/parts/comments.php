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
                <li class="lf-comment<?= ($comment['is_author'] ?? false) === true ? ' lf-comment--author' : '' ?><?= ($comment['pending'] ?? false) === true ? ' lf-comment--pending' : '' ?>" id="<?= lf_e($comment['anchor'] ?? '') ?>">
                    <article>
                        <header class="lf-comment__header">
                            <span class="lf-comment__name"><?= lf_e($comment['name'] ?? '') ?></span>
                            <?php if (($comment['is_author'] ?? false) === true): ?>
                                <span class="lf-comment__badge"><?= lf_e($comment['author_label'] ?? 'Author') ?></span>
                            <?php endif; ?>
                            <time class="lf-comment__date" datetime="<?= lf_e($comment['datetime'] ?? '') ?>"><?= lf_e($comment['posted'] ?? '') ?></time>
                            <?php if (($comment['edited'] ?? false) === true): ?>
                                <span class="lf-comment__edited">(<?= lf_e($comment['edited_label'] ?? 'edited') ?>)</span>
                            <?php endif; ?>
                        </header>
                        <?php if (($comment['pending'] ?? false) === true): ?>
                            <p class="lf-comment__status" role="status"><?= lf_e($comment['pending_label'] ?? '') ?></p>
                        <?php endif; ?>
                        <div class="lf-comment__body"><?= lf_e($comment['body_html'] ?? '') ?></div>
                        <?php if (($comment['edit_url'] ?? '') !== '' || ($comment['report_url'] ?? '') !== '' || is_array($comment['delete'] ?? null)): ?>
                            <footer class="lf-comment__actions">
                                <?php if (($comment['edit_url'] ?? '') !== ''): ?><a class="lf-comment__action" href="<?= lf_e($comment['edit_url']) ?>">Edit</a><?php endif; ?>
                                <?php if (($comment['report_url'] ?? '') !== ''): ?><a class="lf-comment__action" href="<?= lf_e($comment['report_url']) ?>">Report</a><?php endif; ?>
                                <?php if (is_array($comment['delete'] ?? null)): ?>
                                    <form class="lf-comment__delete" method="post" action="<?= lf_e($comment['delete']['action'] ?? '') ?>">
                                        <input type="hidden" name="csrf_token" value="<?= lf_e($comment['delete']['csrf_token'] ?? '') ?>">
                                        <button class="lf-comment__action lf-comment__action--button" type="submit"><?= lf_e($comment['delete']['label'] ?? 'Delete') ?><span class="lf-screen-reader-text"> comment by <?= lf_e($comment['name'] ?? '') ?></span></button>
                                    </form>
                                <?php endif; ?>
                            </footer>
                        <?php endif; ?>
                    </article>
                </li>
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
