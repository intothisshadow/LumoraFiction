<?php

/**
 * Admin: export stories.
 *
 * @package LumoraFiction
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\Story;

/** @var bool $available */
/** @var list<Story> $stories */
/** @var list<array{id: int, name: string, stories: int}> $authors */
/** @var bool $mayAdminister */
/** @var int $userId */
/** @var string $csrfToken */
/** @var string $storyUrl */
/** @var string $authorUrl */
/** @var string $archiveUrl */
?>
<p class="lf-lead">An export is a ZIP file with your stories and everything that goes with them: chapters in the order you put them, the summary, notes and end notes, the classification, dates, the series and collections they belong to, and optionally the cover pictures. It uses an open, documented format, so the stories can be read by other programs and kept as your own copy. Drafts are included, because they are your work. Nobody&rsquo;s login name, email address or password is ever in the file.</p>

<?php if (!$available): ?>
    <div class="lf-notice lf-notice--error" role="alert"><p>This server cannot make ZIP files, so nothing can be exported. Ask your host to enable the ZIP extension.</p></div>
<?php else: ?>
    <section class="lf-card" aria-labelledby="export-story-heading">
        <h2 id="export-story-heading">One story</h2>
        <?php if ($stories === []): ?>
            <p class="lf-hint">You have no stories yet.</p>
        <?php else: ?>
            <form method="post" action="<?= Html::e($storyUrl) ?>" class="lf-form">
                <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                <div class="lf-field">
                    <label for="export_story">Story</label>
                    <select id="export_story" name="story_id">
                        <?php foreach ($stories as $story): ?>
                            <option value="<?= (int) $story->id ?>"><?= Html::e($story->title) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lf-choice">
                    <input type="checkbox" id="export_story_covers" name="covers" value="1">
                    <label for="export_story_covers">Include the cover picture</label>
                </div>
                <p><button type="submit" class="lf-button">Export the story</button></p>
            </form>
        <?php endif; ?>
    </section>

    <section class="lf-card" aria-labelledby="export-mine-heading">
        <h2 id="export-mine-heading">All your stories</h2>
        <form method="post" action="<?= Html::e($authorUrl) ?>" class="lf-form">
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
            <input type="hidden" name="author_id" value="<?= (int) $userId ?>">
            <div class="lf-choice">
                <input type="checkbox" id="export_mine_covers" name="covers" value="1">
                <label for="export_mine_covers">Include the cover pictures</label>
            </div>
            <p><button type="submit" class="lf-button">Export all my stories</button></p>
        </form>
    </section>

    <?php if ($mayAdminister): ?>
        <section class="lf-card" aria-labelledby="export-author-heading">
            <h2 id="export-author-heading">One author&rsquo;s stories</h2>
            <?php if ($authors === []): ?>
                <p class="lf-hint">Nobody has written a story yet.</p>
            <?php else: ?>
                <form method="post" action="<?= Html::e($authorUrl) ?>" class="lf-form">
                    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                    <div class="lf-field">
                        <label for="export_author">Author</label>
                        <select id="export_author" name="author_id">
                            <?php foreach ($authors as $author): ?>
                                <option value="<?= (int) $author['id'] ?>"><?= Html::e($author['name']) ?> (<?= (int) $author['stories'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lf-choice">
                        <input type="checkbox" id="export_author_covers" name="covers" value="1">
                        <label for="export_author_covers">Include the cover pictures</label>
                    </div>
                    <p><button type="submit" class="lf-button">Export this author&rsquo;s stories</button></p>
                </form>
            <?php endif; ?>
        </section>

        <section class="lf-card" aria-labelledby="export-archive-heading">
            <h2 id="export-archive-heading">The whole archive</h2>
            <p class="lf-hint">Every story by every author, in every state, with its chapters. A large archive takes a while to prepare, and it is not a backup of the site: use Backups for that.</p>
            <form method="post" action="<?= Html::e($archiveUrl) ?>" class="lf-form">
                <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                <div class="lf-choice">
                    <input type="checkbox" id="export_archive_covers" name="covers" value="1">
                    <label for="export_archive_covers">Include the cover pictures</label>
                </div>
                <p><button type="submit" class="lf-button">Export the archive</button></p>
            </form>
        </section>
    <?php endif; ?>
<?php endif; ?>
