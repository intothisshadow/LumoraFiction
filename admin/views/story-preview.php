<?php

/**
 * A story as readers will meet it: title, summary, classification and the list of chapters.
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
use LumoraFiction\Models\PublicationState;
use LumoraFiction\Models\Story;
use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;

/** @var Story $story */
/** @var string $owner */
/** @var array<string, list<Term>> $terms */
/** @var list<Chapter> $chapters */
/** @var string $editUrl */
/** @var string $chapterPreviewUrl */
?>
<p class="lf-notice lf-notice--info lf-preview-banner" role="status">This is a preview. Only you and the site staff can see it, and it is <?= Html::e(strtolower($story->state->label())) ?>. <a href="<?= Html::e($editUrl) ?>">Back to editing</a></p>

<article class="lf-reader">
    <header class="lf-reader__header">
        <h2 class="lf-reader__title"><?= Html::e($story->title) ?></h2>
        <p class="lf-reader__byline">by <?= Html::e($owner) ?></p>
        <p class="lf-reader__meta"><?= Html::e($story->workStatus->label()) ?> &middot; <?= number_format($story->wordCount) ?> words &middot; <?= Html::e(strtoupper($story->language)) ?></p>
    </header>

    <?php if ($terms !== []): ?>
        <dl class="lf-reader__terms">
            <?php foreach (TermType::cases() as $type): ?>
                <?php if (isset($terms[$type->value])): ?>
                    <dt><?= Html::e($type->pluralLabel()) ?></dt>
                    <dd><?= Html::e(implode(', ', array_map(static fn (Term $term): string => $term->name, $terms[$type->value]))) ?></dd>
                <?php endif; ?>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>

    <?php if ($story->summary !== ''): ?>
        <section aria-labelledby="preview-summary"><h3 id="preview-summary">Summary</h3><p><?= nl2br(Html::e($story->summary), false) ?></p></section>
    <?php endif; ?>

    <?php if ($story->notes !== ''): ?>
        <section aria-labelledby="preview-notes"><h3 id="preview-notes">Author&rsquo;s notes</h3><p><?= nl2br(Html::e($story->notes), false) ?></p></section>
    <?php endif; ?>

    <?php if ($story->disclaimer !== ''): ?>
        <section aria-labelledby="preview-disclaimer"><h3 id="preview-disclaimer">Disclaimer</h3><p><?= nl2br(Html::e($story->disclaimer), false) ?></p></section>
    <?php endif; ?>

    <section aria-labelledby="preview-chapters">
        <h3 id="preview-chapters">Chapters</h3>
        <?php if ($chapters === []): ?>
            <p>There are no chapters yet.</p>
        <?php else: ?>
            <ol class="lf-reader__chapters">
                <?php foreach ($chapters as $chapter): ?>
                    <li>
                        <a href="<?= Html::e($chapterPreviewUrl . '/' . $chapter->id . '/preview') ?>"><?= Html::e($chapter->title !== '' ? $chapter->title : 'Chapter ' . $chapter->number) ?></a>
                        <span class="lf-hint"><?= number_format($chapter->wordCount) ?> words<?= $chapter->state === PublicationState::Published ? '' : ' &middot; ' . Html::e(strtolower($chapter->state->label())) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
</article>
