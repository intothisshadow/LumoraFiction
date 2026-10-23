<?php

/**
 * Maintenance › Dummy content.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;
use LumoraFiction\Plugins\DummyContent\Options;

/** @var array<string, int|string> $values */
/** @var array<string, string> $errors */
/** @var array{user: int, story: int, term: int} $counts */
/** @var string $generateToken */
/** @var string $removeToken */
/** @var string $generateAction */
/** @var string $removeAction */

$hints = [
    'users' => 'Most become authors and the rest readers. None of them can sign in.',
    'fandoms' => 'Each also gets a few characters and relationships of its own.',
    'tags' => 'Added to the stories, with a few much more popular than the rest.',
    'stories' => 'Mostly published, with some drafts, some awaiting approval and some unpublished. Ratings, genres and categories come from the ones already set up.',
    'max_chapters' => 'About a third of the stories are one chapter. The rest are serials up to this long, with long ones rare.',
    'words' => 'Chapters vary from half to one and a half times this. Longer chapters make a bigger database.',
    'bookmarks' => 'Spread over the generated users and published stories.',
];
?>
<p class="lf-lead">Makes placeholder users, fandoms, tags, stories, chapters and bookmarks so themes, listings, search and feeds can be tried at the size of a real archive. Everything made here is listed, and one button removes all of it.</p>

<?php if ($errors !== []): ?>
    <div class="lf-notice lf-notice--error" role="alert" id="lf-error-summary" tabindex="-1">
        <p>Please fix the following:</p>
        <ul>
            <?php foreach ($errors as $field => $message): ?>
                <li><?= $field === 'form' ? Html::e($message) : '<a href="#' . Html::e($field) . '">' . Html::e($message) . '</a>' ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= Html::e($generateAction) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($generateToken) ?>">

    <fieldset class="lf-card">
        <legend>Make content</legend>
        <p class="lf-hint">Large numbers take a while and fill the database: ten thousand stories of twelve chapters is a sizeable test. Stay on this page until it finishes.</p>

        <?php foreach (Options::FIELDS as $field => [$label]): ?>
            <div class="lf-field<?= isset($errors[$field]) ? ' lf-field--error' : '' ?>">
                <label for="<?= Html::e($field) ?>"><?= Html::e($label) ?></label>
                <input type="text" inputmode="numeric" id="<?= Html::e($field) ?>" name="<?= Html::e($field) ?>" value="<?= Html::e((string) $values[$field]) ?>" maxlength="7" aria-describedby="<?= Html::e($field) ?>_hint<?= isset($errors[$field]) ? ' ' . Html::e($field) . '_error' : '' ?>">
                <p class="lf-hint" id="<?= Html::e($field) ?>_hint"><?= Html::e($hints[$field]) ?></p>
                <?php if (isset($errors[$field])): ?><p class="lf-error" id="<?= Html::e($field) ?>_error"><?= Html::e($errors[$field]) ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="lf-field<?= isset($errors['seed']) ? ' lf-field--error' : '' ?>">
            <label for="seed">Seed (optional)</label>
            <input type="text" inputmode="numeric" id="seed" name="seed" value="<?= Html::e((string) $values['seed']) ?>" maxlength="9" aria-describedby="seed_hint<?= isset($errors['seed']) ? ' seed_error' : '' ?>">
            <p class="lf-hint" id="seed_hint">The same seed and numbers give the same titles, text and links, which helps when comparing two versions of the site. Leave it empty for something different each time.</p>
            <?php if (isset($errors['seed'])): ?><p class="lf-error" id="seed_error"><?= Html::e($errors['seed']) ?></p><?php endif; ?>
        </div>
    </fieldset>

    <p><button type="submit" class="lf-button">Make dummy content</button></p>
</form>

<form method="post" action="<?= Html::e($removeAction) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($removeToken) ?>">

    <fieldset class="lf-card">
        <legend>Remove dummy content</legend>
        <p>Made so far and still here: <strong><?= number_format($counts['user']) ?></strong> users, <strong><?= number_format($counts['story']) ?></strong> stories and <strong><?= number_format($counts['term']) ?></strong> fandoms, characters, relationships and tags.</p>
        <p class="lf-hint">Removing deletes those, their chapters, and every bookmark, reading place and follow that points at them. Nothing you or anyone else wrote is touched. This cannot be undone.</p>

        <div class="lf-choice">
            <input type="checkbox" id="confirm" name="confirm" value="1">
            <label for="confirm">Yes, remove all the dummy content</label>
        </div>
    </fieldset>

    <p><button type="submit" class="lf-button lf-button--secondary">Remove dummy content</button></p>
</form>
