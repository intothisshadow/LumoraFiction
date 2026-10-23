<?php

/**
 * Admin view: one earlier version of a chapter, with a button to go back to it.
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
use LumoraFiction\Models\ChapterRevision;

/** @var Chapter $chapter */
/** @var ChapterRevision $revision */
/** @var string $body trusted markup: the version's text after the content sanitiser */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $listUrl */
?>
<p><a href="<?= Html::e($listUrl) ?>">&larr; All earlier versions</a></p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form lf-card">
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
    <h2>Saved <time datetime="<?= Html::e($revision->createdAt->format('Y-m-d\TH:i:s')) ?>"><?= Html::e($revision->createdAt->format('Y-m-d H:i')) ?></time></h2>
    <p class="lf-hint">Going back gives the chapter this title, text and notes. What it has now is kept as another earlier version, so you can change your mind. A published chapter shows the older text to readers straight away.</p>
    <p><button type="submit" class="lf-button">Go back to this version</button></p>
</form>

<section class="lf-card lf-revision" aria-labelledby="lf-revision-title">
    <h2 id="lf-revision-title"><?= Html::e($revision->title !== '' ? $revision->title : 'No title') ?></h2>
    <?php if ($revision->notes !== ''): ?>
        <p class="lf-hint">Notes: <?= Html::e($revision->notes) ?></p>
    <?php endif; ?>
    <div class="lf-revision__text"><?= $body ?></div>
</section>
