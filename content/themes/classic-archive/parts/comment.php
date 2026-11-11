<?php

/**
 * One comment under a chapter, with the replies that answer it. Printed by the comments part, once for each conversation.
 *
 * @package LumoraFiction
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

$comment = is_array($comment ?? null) ? $comment : [];
$isReply = ($comment['is_reply'] ?? false) === true;
$replies = is_array($comment['replies'] ?? null) ? $comment['replies'] : [];
$delete = is_array($comment['delete'] ?? null) ? $comment['delete'] : null;
$hasActions = ($comment['edit_url'] ?? '') !== '' || ($comment['report_url'] ?? '') !== '' || ($comment['reply_url'] ?? '') !== '' || $delete !== null;

?>
<li class="lf-comment<?= $isReply ? ' lf-comment__reply' : '' ?><?= ($comment['is_author'] ?? false) === true ? ' lf-comment--author' : '' ?><?= ($comment['pending'] ?? false) === true ? ' lf-comment--pending' : '' ?>" id="<?= lf_e($comment['anchor'] ?? '') ?>">
    <article>
        <header class="lf-comment__header">
            <?php if (($comment['reply_to_label'] ?? '') !== ''): ?>
                <span class="lf-screen-reader-text"><?= lf_e($comment['reply_to_label']) ?>: </span>
            <?php endif; ?>
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
        <?php if ($hasActions): ?>
            <footer class="lf-comment__actions">
                <?php if (($comment['reply_url'] ?? '') !== ''): ?><a class="lf-comment__action lf-comment__action--reply" href="<?= lf_e($comment['reply_url']) ?>"><?= lf_e($comment['reply_label'] ?? 'Reply') ?><span class="lf-screen-reader-text"> to <?= lf_e($comment['name'] ?? '') ?></span></a><?php endif; ?>
                <?php if (($comment['edit_url'] ?? '') !== ''): ?><a class="lf-comment__action" href="<?= lf_e($comment['edit_url']) ?>">Edit</a><?php endif; ?>
                <?php if (($comment['report_url'] ?? '') !== ''): ?><a class="lf-comment__action" href="<?= lf_e($comment['report_url']) ?>">Report</a><?php endif; ?>
                <?php if ($delete !== null && ($delete['confirm_url'] ?? '') !== ''): ?>
                    <a class="lf-comment__action" href="<?= lf_e($delete['confirm_url']) ?>"><?= lf_e($delete['label'] ?? 'Delete') ?><span class="lf-screen-reader-text"> comment by <?= lf_e($comment['name'] ?? '') ?> and its replies</span></a>
                <?php elseif ($delete !== null): ?>
                    <form class="lf-comment__delete" method="post" action="<?= lf_e($delete['action'] ?? '') ?>">
                        <input type="hidden" name="csrf_token" value="<?= lf_e($delete['csrf_token'] ?? '') ?>">
                        <button class="lf-comment__action lf-comment__action--button" type="submit"><?= lf_e($delete['label'] ?? 'Delete') ?><span class="lf-screen-reader-text"> comment by <?= lf_e($comment['name'] ?? '') ?></span></button>
                    </form>
                <?php endif; ?>
            </footer>
        <?php endif; ?>
    </article>
    <?php if ($replies !== []): ?>
        <ol class="lf-comment__replies">
            <?php foreach ($replies as $reply): ?>
                <?php lf_part('comment', ['comment' => $reply]); ?>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</li>
