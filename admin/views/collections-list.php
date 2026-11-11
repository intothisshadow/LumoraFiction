<?php

/**
 * Admin: collections a person runs, collections open to their stories, and what they have offered.
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
use LumoraFiction\Models\Collection;
use LumoraFiction\Models\Story;

/** @var list<array{collection: Collection, approved: int, pending: int, owner: string}> $rows */
/** @var bool $all */
/** @var bool $mayListAll */
/** @var bool $mayCreate */
/** @var list<Collection> $open */
/** @var list<array{collection: Collection, story: Story, state: string}> $submissions */
/** @var list<Story> $myStories */
/** @var string $csrfToken */
/** @var string $newUrl */
/** @var string $baseUrl */
?>
<p class="lf-lead">A collection gathers stories that do not need to be in order: an anthology, a challenge or event, a reading list. The person who runs it does not own the stories in it. Authors offer their own stories, and an author can always take theirs out again.</p>

<section aria-labelledby="collections-run-heading">
    <h2 id="collections-run-heading"><?= $all ? 'Every collection' : 'Collections you run' ?></h2>
    <p>
        <?php if ($mayCreate): ?><a class="lf-button" href="<?= Html::e($newUrl) ?>">Add a collection</a><?php endif; ?>
        <?php if ($mayListAll): ?>
            <?php if ($all): ?>
                <a href="<?= Html::e($baseUrl) ?>">Show only mine</a>
            <?php else: ?>
                <a href="<?= Html::e($baseUrl . '?scope=all') ?>">Show every collection</a>
            <?php endif; ?>
        <?php endif; ?>
    </p>

    <?php if ($rows === []): ?>
        <p class="lf-hint"><?= $mayCreate ? 'You do not run a collection yet.' : 'You do not run a collection, and this site does not let your account start one.' ?></p>
    <?php else: ?>
        <table class="lf-table">
            <caption class="lf-visually-hidden">Collections</caption>
            <thead>
                <tr>
                    <th scope="col">Title</th>
                    <?php if ($all): ?><th scope="col">Curator</th><?php endif; ?>
                    <th scope="col">Who can see it</th>
                    <th scope="col">Stories</th>
                    <th scope="col">Waiting</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $collection = $row['collection']; ?>
                    <tr>
                        <th scope="row"><a href="<?= Html::e($baseUrl . '/' . $collection->id) ?>"><?= Html::e($collection->title) ?></a></th>
                        <?php if ($all): ?><td><?= Html::e($row['owner']) ?></td><?php endif; ?>
                        <td><?= $collection->isPublic ? 'Everyone' : 'Only the curator and staff' ?></td>
                        <td><?= (int) $row['approved'] ?></td>
                        <td><?= (int) $row['pending'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section aria-labelledby="collections-open-heading">
    <h2 id="collections-open-heading">Collections open to your stories</h2>
    <?php if ($open === [] || $myStories === []): ?>
        <p class="lf-hint"><?= $myStories === [] ? 'You have no stories to offer yet.' : 'No other collection is taking stories right now.' ?></p>
    <?php else: ?>
        <?php foreach ($open as $collection): ?>
            <form method="post" action="<?= Html::e($baseUrl . '/' . $collection->id . '/submit') ?>" class="lf-form lf-card">
                <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                <h3><?= Html::e($collection->title) ?></h3>
                <?php if (trim($collection->description) !== ''): ?><p class="lf-hint"><?= Html::e(mb_strimwidth($collection->description, 0, 200, '…', 'UTF-8')) ?></p><?php endif; ?>
                <p class="lf-hint"><?= $collection->requiresApproval ? 'Its curator approves each story before it shows.' : 'Stories you offer show at once.' ?></p>
                <div class="lf-field">
                    <label for="story_<?= (int) $collection->id ?>">Story to offer</label>
                    <select id="story_<?= (int) $collection->id ?>" name="story_id">
                        <?php foreach ($myStories as $story): ?>
                            <option value="<?= (int) $story->id ?>"><?= Html::e($story->title) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lf-field">
                    <label for="note_<?= (int) $collection->id ?>">Note for the curator (optional)</label>
                    <input type="text" id="note_<?= (int) $collection->id ?>" name="note" maxlength="300">
                </div>
                <p><button type="submit" class="lf-button lf-button--secondary">Offer the story</button></p>
            </form>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<?php if ($submissions !== []): ?>
    <section aria-labelledby="collections-offered-heading">
        <h2 id="collections-offered-heading">What you have offered</h2>
        <table class="lf-table">
            <caption class="lf-visually-hidden">Stories you have offered to other people&rsquo;s collections</caption>
            <thead>
                <tr>
                    <th scope="col">Story</th>
                    <th scope="col">Collection</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="lf-visually-hidden">Action</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($submissions as $entry): ?>
                    <tr>
                        <th scope="row"><?= Html::e($entry['story']->title) ?></th>
                        <td><?= Html::e($entry['collection']->title) ?></td>
                        <td><?= $entry['state'] === 'approved' ? 'In the collection' : 'Waiting for approval' ?></td>
                        <td>
                            <form method="post" action="<?= Html::e($baseUrl . '/' . $entry['collection']->id . '/withdraw') ?>" class="lf-inline-form">
                                <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                                <input type="hidden" name="story_id" value="<?= (int) $entry['story']->id ?>">
                                <button type="submit" class="lf-button lf-button--secondary">Take out<span class="lf-visually-hidden"> <?= Html::e($entry['story']->title) ?></span></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>
