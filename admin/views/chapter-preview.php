<?php

/**
 * A chapter as readers will read it: the sanitised text with its notes and links to the neighbouring chapters.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\Chapter;
use LumoraFiction\Models\Story;

/** @var Story $story */
/** @var Chapter $chapter */
/** @var string $html trusted markup from the content renderer, already sanitised */
/** @var Chapter|null $previous */
/** @var Chapter|null $next */
/** @var string $editUrl */
/** @var string $storyPreviewUrl */
/** @var string $chapterPreviewUrl */

$label = static fn (Chapter $item): string => $item->title !== '' ? $item->title : 'Chapter ' . $item->number;
?>
<p class="lf-notice lf-notice--info lf-preview-banner" role="status">This is a preview. Only you and the site staff can see it, and it is <?= Html::e(strtolower($chapter->state->label())) ?>. <a href="<?= Html::e($editUrl) ?>">Back to editing</a></p>

<article class="lf-reader">
    <header class="lf-reader__header">
        <p class="lf-reader__meta"><a href="<?= Html::e($storyPreviewUrl) ?>"><?= Html::e($story->title) ?></a></p>
        <h2 class="lf-reader__title"><?= Html::e($label($chapter)) ?></h2>
        <p class="lf-reader__meta">Chapter <?= (int) $chapter->number ?> &middot; <?= number_format($chapter->wordCount) ?> words</p>
    </header>

    <?php if ($chapter->notes !== ''): ?>
        <aside class="lf-reader__notes" aria-label="Author&rsquo;s notes"><p><?= nl2br(Html::e($chapter->notes), false) ?></p></aside>
    <?php endif; ?>

    <div class="lf-reader__text"><?= $html ?></div>

    <nav class="lf-reader__nav" aria-label="Chapters">
        <?php if ($previous !== null): ?><a href="<?= Html::e($chapterPreviewUrl . '/' . $previous->id . '/preview') ?>" rel="prev">&larr; <?= Html::e($label($previous)) ?></a><?php endif; ?>
        <?php if ($next !== null): ?><a href="<?= Html::e($chapterPreviewUrl . '/' . $next->id . '/preview') ?>" rel="next"><?= Html::e($label($next)) ?> &rarr;</a><?php endif; ?>
    </nav>
</article>
