<?php

/**
 * Admin: add or edit a collection, work its approval queue and add the curator's own stories.
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

use LumoraFiction\Core\Admin\BulkBar;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\Collection;
use LumoraFiction\Models\Story;

/** @var Collection|null $collection */
/** @var array{title: string, description: string, visibility: string, accepts_submissions: bool, requires_approval: bool} $values */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var list<array{story: Story, state: string, addedBy: int, note: string, createdAt: DateTimeImmutable}> $pending */
/** @var list<array{story: Story, state: string, addedBy: int, note: string, createdAt: DateTimeImmutable}> $approved */
/** @var list<Story> $available */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $baseUrl */
/** @var string $storyUrl */
/** @var string $publicUrl */
/** @var array{title: int, description: int, note: int} $limits */
/** @var string $assetVersion */

$problem = static fn (string $name): string => isset($errors[$name]) ? '<p class="lf-error" id="' . Html::e($name) . '_error">' . Html::e($errors[$name]) . '</p>' : '';
$describe = static fn (string $name): string => ' aria-describedby="' . Html::e($name) . '_hint' . (isset($errors[$name]) ? ' ' . Html::e($name) . '_error' : '') . '"';
?>
<?= $errorSummary ?>

<p><a href="<?= Html::e($baseUrl) ?>">&larr; All collections</a></p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate data-lf-guard>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-field<?= isset($errors['title']) ? ' lf-field--error' : '' ?>">
        <label for="title">Title</label>
        <input type="text" id="title" name="title" value="<?= Html::e($values['title']) ?>" maxlength="<?= (int) $limits['title'] ?>"<?= $describe('title') ?>>
        <p class="lf-hint" id="title_hint">What readers see at the top of the collection page.</p>
        <?= $problem('title') ?>
    </div>

    <div class="lf-field<?= isset($errors['description']) ? ' lf-field--error' : '' ?>">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="4" maxlength="<?= (int) $limits['description'] ?>"<?= $describe('description') ?>><?= Html::e($values['description']) ?></textarea>
        <p class="lf-hint" id="description_hint">What the collection is for, such as an event, a theme or the rules. Optional.</p>
        <?= $problem('description') ?>
    </div>

    <fieldset class="lf-card">
        <legend>Who can see it</legend>
        <div class="lf-choice">
            <input type="radio" id="visibility_public" name="visibility" value="public"<?= $values['visibility'] === 'public' ? ' checked' : '' ?>>
            <label for="visibility_public">Everyone</label>
        </div>
        <div class="lf-choice">
            <input type="radio" id="visibility_private" name="visibility" value="private"<?= $values['visibility'] === 'private' ? ' checked' : '' ?> aria-describedby="visibility_hint">
            <label for="visibility_private">Only me and the site staff</label>
        </div>
        <p class="lf-hint" id="visibility_hint">A story is shown only to people who could already open it, whichever you choose. A private collection takes no submissions.</p>
        <?= $problem('visibility') ?>
    </fieldset>

    <fieldset class="lf-card">
        <legend>Submissions</legend>
        <div class="lf-choice">
            <input type="checkbox" id="accepts_submissions" name="accepts_submissions" value="1"<?= $values['accepts_submissions'] ? ' checked' : '' ?>>
            <label for="accepts_submissions">Let other authors offer their stories</label>
        </div>
        <div class="lf-choice">
            <input type="checkbox" id="requires_approval" name="requires_approval" value="1"<?= $values['requires_approval'] ? ' checked' : '' ?> aria-describedby="requires_approval_hint">
            <label for="requires_approval">I approve each story before it shows</label>
        </div>
        <p class="lf-hint" id="requires_approval_hint">Your own stories always go straight in.</p>
    </fieldset>

    <p><button type="submit" class="lf-button"><?= $collection === null ? 'Add the collection' : 'Save changes' ?></button></p>
</form>

<?php if ($collection !== null): ?>
    <?php if ($pending !== []): ?>
        <section class="lf-card" aria-labelledby="collection-pending-heading">
            <h2 id="collection-pending-heading">Waiting for your approval</h2>
            <?= BulkBar::form('lf-bulk-pending', $baseUrl . '/' . $collection->id . '/bulk', $csrfToken) ?>
            <?= BulkBar::bar('lf-bulk-pending', 'offered stories', ['approve' => 'Approve', 'turndown' => 'Turn down'], '', 'pending_ids') ?>
            <?= BulkBar::selectAll('pending_ids', 'lf-bulk-pending', 'offered stories') ?>
            <ul class="lf-collection-members">
                <?php foreach ($pending as $member): ?>
                    <li class="lf-collection-members__item">
                        <?= BulkBar::input('pending_ids', 'lf-bulk-pending', $member['story']->id, 'Select ' . $member['story']->title) ?>
                        <strong><?= Html::e($member['story']->title) ?></strong>
                        <?php if ($member['note'] !== ''): ?><span class="lf-hint">Note: <?= Html::e($member['note']) ?></span><?php endif; ?>
                        <?php foreach (['approve' => 'Approve', 'remove' => 'Turn down'] as $step => $label): ?>
                            <form method="post" action="<?= Html::e($baseUrl . '/' . $collection->id . '/' . $step) ?>" class="lf-inline-form">
                                <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                                <input type="hidden" name="story_id" value="<?= (int) $member['story']->id ?>">
                                <button type="submit" class="lf-button lf-button--secondary"><?= Html::e($label) ?><span class="lf-visually-hidden"> <?= Html::e($member['story']->title) ?></span></button>
                            </form>
                        <?php endforeach; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <section class="lf-card" aria-labelledby="collection-stories-heading">
        <h2 id="collection-stories-heading">Stories in this collection</h2>
        <?php if ($approved === []): ?>
            <p class="lf-hint">No stories yet.</p>
        <?php else: ?>
            <?= BulkBar::form('lf-bulk-approved', $baseUrl . '/' . $collection->id . '/bulk', $csrfToken) ?>
            <?= BulkBar::bar('lf-bulk-approved', 'stories', ['remove' => 'Take out of the collection'], '', 'approved_ids') ?>
            <?= BulkBar::selectAll('approved_ids', 'lf-bulk-approved', 'stories') ?>
            <ul class="lf-collection-members">
                <?php foreach ($approved as $member): ?>
                    <li class="lf-collection-members__item">
                        <?= BulkBar::input('approved_ids', 'lf-bulk-approved', $member['story']->id, 'Select ' . $member['story']->title) ?>
                        <?= Html::e($member['story']->title) ?>
                        <span class="lf-hint"><?= Html::e($member['story']->state->label()) ?></span>
                        <form method="post" action="<?= Html::e($baseUrl . '/' . $collection->id . '/remove') ?>" class="lf-inline-form">
                            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                            <input type="hidden" name="story_id" value="<?= (int) $member['story']->id ?>">
                            <button type="submit" class="lf-button lf-button--secondary">Take out<span class="lf-visually-hidden"> <?= Html::e($member['story']->title) ?></span></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($available !== []): ?>
            <form method="post" action="<?= Html::e($baseUrl . '/' . $collection->id . '/submit') ?>" class="lf-form">
                <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                <div class="lf-field">
                    <label for="story_id">Add one of your own stories</label>
                    <select id="story_id" name="story_id">
                        <?php foreach ($available as $story): ?>
                            <option value="<?= (int) $story->id ?>"><?= Html::e($story->title) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p><button type="submit" class="lf-button lf-button--secondary">Add to the collection</button></p>
            </form>
        <?php endif; ?>
        <p class="lf-hint">You can add only your own stories. Other authors offer theirs, and you decide whether they stay.</p>
    </section>

    <p>
        <?php if ($publicUrl !== ''): ?><a href="<?= Html::e($publicUrl) ?>">View it on the site</a> &middot;<?php endif; ?>
        <a href="<?= Html::e($baseUrl . '/' . $collection->id . '/delete') ?>">Delete this collection</a>
    </p>

    <?= BulkBar::script($assetVersion) ?>
<?php endif; ?>
