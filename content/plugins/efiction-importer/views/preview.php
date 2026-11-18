<?php

/**
 * Maintenance › eFiction importer: what was found, how it will be mapped, and the start button.
 *
 * @package LumoraFiction
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.5.0
 */

declare(strict_types=1);

use LumoraFiction\Helpers\Html;
use LumoraFiction\Services\CommentService;

/** @var array<string, mixed> $facts */
/** @var array<string, mixed> $mapping */
/** @var list<array{id: int, name: string, title: string}> $pages */
/** @var list<\LumoraFiction\Models\Term> $fandoms */
/** @var list<\LumoraFiction\Models\Term> $ratings */
/** @var string $storiesFolder */
/** @var array<string, string> $kinds */
/** @var string $startToken */
/** @var string $startAction */
/** @var string $forgetToken */
/** @var string $forgetAction */
/** @var string $backupUrl */
/** @var string $maintenanceUrl */
/** @var string $homeUrl */

$n = static fn (mixed $value): string => number_format((int) $value);
$check = $facts['chapter_check'];
$already = $facts['already'];
$kindNames = ['genre' => 'Genres', 'warning' => 'Archive warnings', 'tag' => 'Tags', 'skip' => 'Leave out'];
?>
<p class="lf-lead">Nothing has been written yet. This is what the importer found in eFiction<?= $facts['version'] !== '' ? ' ' . Html::e((string) $facts['version']) : '' ?> and what it would do. Check the mapping below, then start.</p>

<?php if ($already !== []): ?>
    <div class="lf-notice lf-notice--info" role="status"><p>An earlier import already brought <?= $n($already['story'] ?? 0) ?> stories and <?= $n($already['user'] ?? 0) ?> accounts across. Running again adds only what is new and skips what was brought before.</p></div>
<?php endif; ?>

<section class="lf-card" aria-labelledby="efi-found-heading">
    <h2 id="efi-found-heading">What was found</h2>
    <table class="lf-table">
        <caption class="lf-visually-hidden">Counts found in the eFiction database</caption>
        <thead><tr><th scope="col">What</th><th scope="col">How many</th><th scope="col">What happens</th></tr></thead>
        <tbody>
            <tr><th scope="row">Authors and members</th><td><?= $n($facts['authors']) ?></td><td>Already an account here with the same email address, matched and not copied: <?= $n($facts['email_clashes']) ?>. No usable email address, so a placeholder one is used: <?= $n($facts['bad_emails']) ?>. Keeping their old password: <?= $n($facts['bcrypt']) ?>. Setting a new one by the usual reset: <?= $n($facts['legacy_hashes']) ?>.</td></tr>
            <tr><th scope="row">Staff and accounts an administrator made</th><td><?= $n($facts['staff']) ?> and <?= $n($facts['admin_made']) ?></td><td>Listed in the report. No roles are given automatically.</td></tr>
            <tr><th scope="row">Stories</th><td><?= $n($facts['stories']) ?></td><td>Validated, so published: <?= $n($facts['stories_validated']) ?>. Skipped for having no chapters: <?= $n($facts['stories_no_chapters']) ?>. Skipped for having no author: <?= $n($facts['stories_no_author']) ?>.</td></tr>
            <tr><th scope="row">Chapters</th><td><?= $n($facts['chapters']) ?></td><td>Text is kept <?= $facts['store'] === 'db' ? 'in the database' : 'in files' ?> according to eFiction's settings<?= $storiesFolder !== '' ? ' (stories folder: <code>' . Html::e($storiesFolder) . '</code>)' : '' ?>. Of the first <?= $n($check['checked']) ?> checked: text in the database <?= $n($check['database']) ?>, text in a file <?= $n($check['file']) ?>, no text <?= $n($check['none']) ?>, character set repaired <?= $n($check['converted']) ?>.</td></tr>
            <tr><th scope="row">Fandoms, characters, ratings, classes</th><td><?= $n($facts['categories']) ?>, <?= $n($facts['characters']) ?>, <?= $n($facts['ratings']) ?>, <?= $n($facts['classes']) ?></td><td>Mapped below. Nothing is added to a list without being shown here.</td></tr>
            <tr><th scope="row">Reviews, favourites, news, pages</th><td><?= $n($facts['reviews']) ?> (and <?= $n($facts['guest_reviews']) ?> by guests), <?= $n($facts['favorites']) ?>, <?= $n($facts['news']) ?>, <?= $n(count($pages)) ?></td><td>Each is off until ticked below.</td></tr>
            <tr><th scope="row">Series and co-authors</th><td><?= $n($facts['series']) ?> and <?= $n($facts['coauthored']) ?></td><td>Reported only. Co-authors are not supported, and a story belongs to its main author.</td></tr>
        </tbody>
    </table>
</section>

<form method="post" action="<?= Html::e($startAction) ?>" class="lf-form">
    <input type="hidden" name="csrf_token" value="<?= Html::e($startToken) ?>">

    <?php if ($mapping['ratings'] !== []): ?>
        <section class="lf-card" aria-labelledby="efi-ratings-heading">
            <h2 id="efi-ratings-heading">Ratings</h2>
            <table class="lf-table">
                <caption class="lf-visually-hidden">How each eFiction rating becomes a rating here</caption>
                <thead><tr><th scope="col">eFiction rating</th><th scope="col">Stories</th><th scope="col">Becomes</th></tr></thead>
                <tbody>
                    <?php foreach ($mapping['ratings'] as $rating): ?>
                        <tr>
                            <th scope="row"><?= Html::e($rating['name']) ?></th>
                            <td><?= $n($rating['count']) ?></td>
                            <td>
                                <label class="lf-visually-hidden" for="rating_<?= (int) $rating['id'] ?>">Rating for <?= Html::e($rating['name']) ?></label>
                                <select id="rating_<?= (int) $rating['id'] ?>" name="rating_<?= (int) $rating['id'] ?>">
                                    <option value="new"<?= $rating['choice'] === 'new' ? ' selected' : '' ?>>Make a new rating with this name</option>
                                    <?php foreach ($ratings as $term): ?>
                                        <option value="<?= $term->id ?>"<?= $rating['choice'] === $term->id ? ' selected' : '' ?>>Use "<?= Html::e($term->name) ?>"</option>
                                    <?php endforeach; ?>
                                    <option value="skip">Leave out (the story gets no rating)</option>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <?php if ($mapping['categories'] !== []): ?>
        <section class="lf-card" aria-labelledby="efi-cats-heading">
            <h2 id="efi-cats-heading">Categories become fandoms</h2>
            <p class="lf-hint">A category with stories in it is made a fandom, or matched to the fandom with the same name. One that only groups other categories, and one nothing uses, is left out. Choose an existing fandom to merge a category into it.</p>
            <table class="lf-table">
                <caption class="lf-visually-hidden">How each eFiction category becomes a fandom</caption>
                <thead><tr><th scope="col">eFiction category</th><th scope="col">Stories</th><th scope="col">Becomes</th></tr></thead>
                <tbody>
                    <?php foreach ($mapping['categories'] as $category): ?>
                        <tr>
                            <th scope="row"><?= $category['parent'] !== '' ? Html::e($category['parent']) . ' › ' : '' ?><?= Html::e($category['name']) ?></th>
                            <td><?= $n($category['count']) ?></td>
                            <td>
                                <label class="lf-visually-hidden" for="category_<?= (int) $category['id'] ?>">Fandom for <?= Html::e($category['name']) ?></label>
                                <select id="category_<?= (int) $category['id'] ?>" name="category_<?= (int) $category['id'] ?>">
                                    <option value="import"<?= $category['choice'] === 'import' ? ' selected' : '' ?>>A fandom with this name</option>
                                    <option value="skip"<?= $category['choice'] === 'skip' ? ' selected' : '' ?>>Leave out</option>
                                    <?php foreach ($fandoms as $term): ?>
                                        <option value="<?= $term->id ?>">Merge into "<?= Html::e($term->name) ?>"</option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <section class="lf-card" aria-labelledby="efi-other-heading">
        <h2 id="efi-other-heading">Characters, genres and warnings</h2>
        <?php if ($mapping['characters']['total'] > 0): ?>
            <div class="lf-field">
                <label for="characters">Characters (<?= $n($mapping['characters']['total']) ?>, <?= $n($mapping['characters']['used']) ?> used by stories)</label>
                <select id="characters" name="characters">
                    <option value="import" selected>Make them characters, under the fandom they belonged to</option>
                    <option value="skip">Leave them out</option>
                </select>
            </div>
        <?php endif; ?>

        <?php foreach ($mapping['classtypes'] as $type): ?>
            <div class="lf-field">
                <label for="classtype_<?= (int) $type['id'] ?>"><?= Html::e($type['name']) ?> (<?= $n($type['classes']) ?> classes, used <?= $n($type['count']) ?> times) becomes</label>
                <select id="classtype_<?= (int) $type['id'] ?>" name="classtype_<?= (int) $type['id'] ?>">
                    <?php foreach ($kindNames as $value => $label): ?>
                        <option value="<?= Html::e($value) ?>"<?= $type['choice'] === $value ? ' selected' : '' ?>><?= Html::e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="lf-card" aria-labelledby="efi-options-heading">
        <h2 id="efi-options-heading">What else to bring</h2>

        <div class="lf-field">
            <label for="unvalidated">Stories eFiction had not validated yet</label>
            <select id="unvalidated" name="unvalidated">
                <option value="draft" selected>Become drafts</option>
                <option value="approval">Go to the approval queue</option>
            </select>
            <p class="lf-hint">Validated stories are published. A story that would publish without review for its author here is left as a draft rather than published.</p>
        </div>

        <div class="lf-choice"><input type="checkbox" id="opt_bio" name="opt_bio" value="1"><label for="opt_bio">Author biographies (real names and websites are never imported)</label></div>
        <div class="lf-choice"><input type="checkbox" id="opt_reviews" name="opt_reviews" value="1"><label for="opt_reviews">Story reviews by members, as comments (<?= $n($facts['reviews']) ?>)</label></div>
        <div class="lf-choice"><input type="checkbox" id="opt_guest_reviews" name="opt_guest_reviews" value="1"><label for="opt_guest_reviews">Guest reviews, as comments under your account (<?= $n($facts['guest_reviews']) ?>)</label></div>
        <p class="lf-hint">A guest review keeps the reviewer's name, date and text. It belongs to your account but is shown under the reviewer's name, followed by the word <?= Html::e(CommentService::GUEST_LABEL) ?> in brackets, and nobody is notified of it. eFiction keeps no email address for a guest, so none is stored.</p>
        <div class="lf-choice"><input type="checkbox" id="opt_favorites" name="opt_favorites" value="1"><label for="opt_favorites">Favourite stories, as bookmarks (<?= $n($facts['favorites']) ?>)</label></div>
        <div class="lf-choice"><input type="checkbox" id="opt_news" name="opt_news" value="1"><label for="opt_news">News posts (<?= $n($facts['news']) ?>)</label></div>

        <?php if ($pages !== []): ?>
            <fieldset>
                <legend>Pages to bring across as draft pages</legend>
                <p class="lf-hint">eFiction keeps its custom pages and its welcome, copyright and policy texts as messages. Tick the ones to bring. They arrive as drafts to check before anyone reads them, and none takes an address the site already uses.</p>
                <?php foreach ($pages as $page): ?>
                    <div class="lf-choice"><input type="checkbox" id="page_<?= (int) $page['id'] ?>" name="pages[]" value="<?= (int) $page['id'] ?>"><label for="page_<?= (int) $page['id'] ?>"><?= Html::e($page['title'] !== '' ? $page['title'] : $page['name']) ?> <span class="lf-hint">(<?= Html::e($page['name']) ?>)</span></label></div>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>
    </section>

    <section class="lf-card" aria-labelledby="efi-start-heading">
        <h2 id="efi-start-heading">Start</h2>
        <p>Take a backup first: <a href="<?= Html::e($backupUrl) ?>">Maintenance › Backups</a>. Consider switching on <a href="<?= Html::e($maintenanceUrl) ?>">maintenance mode</a> while this runs, since a large archive takes a while and readers would see it fill up. The import works a few items at a time on a page that carries on by itself; keep it open until it finishes.</p>
        <div class="lf-choice"><input type="checkbox" id="confirm" name="confirm" value="1"><label for="confirm">I have taken a backup of this site</label></div>
        <p><button type="submit" class="lf-button">Start the import</button> <a class="lf-button lf-button--secondary" href="<?= Html::e($homeUrl) ?>">Back</a></p>
    </section>
</form>
