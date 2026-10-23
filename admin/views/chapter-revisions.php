<?php

/**
 * Admin view: the earlier versions kept of one chapter.
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
/** @var list<ChapterRevision> $revisions newest first */
/** @var array<int, string> $authors display names by account id */
/** @var string $baseUrl */
/** @var string $backUrl */
?>
<p><a href="<?= Html::e($backUrl) ?>">&larr; Back to the chapter</a></p>

<p class="lf-lead">A version is saved each time a change replaces the text, so you can go back to an earlier one. Only the newest versions are kept; the Administrator sets how many.</p>

<?php if ($revisions === []): ?>
    <p class="lf-hint">There are no earlier versions yet. The first one is saved the next time you change this chapter.</p>
<?php else: ?>
    <table class="lf-table lf-revision-list">
        <caption class="lf-visually-hidden">Earlier versions of the chapter, newest first</caption>
        <thead>
            <tr>
                <th scope="col">Saved</th>
                <th scope="col">Title</th>
                <th scope="col">Words</th>
                <th scope="col">Changed by</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($revisions as $revision): ?>
                <tr>
                    <th scope="row"><a href="<?= Html::e($baseUrl . '/' . $revision->id) ?>"><time datetime="<?= Html::e($revision->createdAt->format('Y-m-d\TH:i:s')) ?>"><?= Html::e($revision->createdAt->format('Y-m-d H:i')) ?></time></a></th>
                    <td><?= Html::e($revision->title !== '' ? $revision->title : 'No title') ?></td>
                    <td><?= number_format($revision->wordCount) ?></td>
                    <td><?= Html::e($authors[$revision->createdBy ?? 0] ?? 'Unknown') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
