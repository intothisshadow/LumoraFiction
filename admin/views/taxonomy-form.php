<?php

/**
 * Adds a classification term, or edits one: its name, review status and aliases.
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

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Helpers\Html;
use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;
use LumoraFiction\Services\TaxonomyService;

/** @var string $mode create or edit */
/** @var list<Term>|null $characters the characters of a fandom being edited; null when the kind has none */
/** @var int $characterTotal */
/** @var TermType $type */
/** @var Term|null $term */
/** @var list<array{id: int, alias: string}> $aliases */
/** @var string $parentName */
/** @var array{name: string, fandom: string, alias: string, characters?: string, move?: string, description?: string, hidden?: bool} $values */
/** @var array<string, string> $errors */
/** @var string $errorSummary trusted markup from the error-summary view */
/** @var bool $curated */
/** @var string $csrfToken */
/** @var string $action */
/** @var string $listUrl */
/** @var array<string, string> $urls */

$editing = $mode === 'edit' && $term !== null;
?>
<?= $errorSummary ?>

<p><a href="<?= Html::e($listUrl) ?>">&larr; <?= Html::e($labels->plural($type)) ?></a></p>

<form method="post" action="<?= Html::e($action) ?>" class="lf-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">

    <div class="lf-card">
        <h2><?= $editing ? 'Name' : 'New ' . Html::e($labels->lower($type)) ?></h2>

        <div class="lf-field<?= isset($errors['name']) ? ' lf-field--error' : '' ?>">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" value="<?= Html::e($values['name']) ?>" maxlength="100" required<?= !$editing ? ' autocomplete="off" data-lf-lookup="warn" data-lf-lookup-type="' . Html::e($type->value) . '"' . ($type->belongsToFandom() ? ' data-lf-lookup-fandom="fandom"' : '') : '' ?> aria-describedby="name_hint<?= isset($errors['name']) ? ' name_error' : '' ?>">
            <p class="lf-hint" id="name_hint">Up to 100 characters, without commas.<?= $editing ? ' Changing the name does not change the term\'s address. Choosing one of its aliases swaps the two.' : '' ?></p>
            <?php if (isset($errors['name'])): ?><p class="lf-error" id="name_error"><?= Html::e($errors['name']) ?></p><?php endif; ?>
        </div>

        <?php if ($type->belongsToFandom()): ?>
            <div class="lf-field<?= isset($errors['fandom']) ? ' lf-field--error' : '' ?>">
                <label for="fandom">Fandom</label>
                <input type="text" id="fandom" name="fandom" value="<?= Html::e($values['fandom']) ?>" maxlength="100" aria-describedby="fandom_hint<?= isset($errors['fandom']) ? ' fandom_error' : '' ?>">
                <p class="lf-hint" id="fandom_hint"><?= $editing ? 'The name of the fandom this belongs to, or empty for none. Changing it moves the ' . Html::e($labels->lower($type)) . ' and its aliases to that fandom; stories keep it.' : 'The name of the fandom this belongs to, or leave empty if it belongs to none.' ?> The same name in two fandoms is two separate terms.</p>
                <?php if (isset($errors['fandom'])): ?><p class="lf-error" id="fandom_error"><?= Html::e($errors['fandom']) ?></p><?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($editing && $curated): ?>
            <div class="lf-field<?= isset($errors['name']) && str_contains($errors['name'], 'description') ? ' lf-field--error' : '' ?>">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="2" maxlength="300" aria-describedby="description_hint"><?= Html::e((string) ($values['description'] ?? $term->description)) ?></textarea>
                <p class="lf-hint" id="description_hint">Shown to writers next to this choice. Up to 300 characters. Optional.</p>
            </div>

            <div class="lf-choice">
                <input type="checkbox" id="hidden" name="hidden" value="1"<?= ($values['hidden'] ?? $term->hidden) ? ' checked' : '' ?>>
                <label for="hidden">Hidden</label>
                <p class="lf-hint">A hidden <?= Html::e($labels->lower($type)) ?> cannot be chosen for new stories and is left out of the public indexes and filters. Stories that already have it keep it.</p>
            </div>
        <?php endif; ?>

        <?php if ($editing): ?>
            <div class="lf-choice">
                <input type="checkbox" id="reviewed" name="reviewed" value="1"<?= $term->reviewed ? ' checked' : '' ?>>
                <label for="reviewed">Approved</label>
                <p class="lf-hint">Untick to mark a term as unapproved, waiting for review. Terms writers propose start unticked.</p>
            </div>
            <p class="lf-hint"><?= (int) $term->storyCount ?> public <?= $term->storyCount === 1 ? 'story uses' : 'stories use' ?> this term. Address: <code><?= Html::e($term->slug) ?></code></p>
        <?php endif; ?>

        <p><button type="submit" class="lf-button"><?= $editing ? 'Save changes' : 'Add ' . Html::e($labels->lower($type)) ?></button></p>
    </div>
</form>

<?php if (!$editing || ($characters ?? null) !== null): ?>
    <script src="<?= Html::e(AdminUrl::asset('js/term-lookup.js', $assetVersion ?? '')) ?>" data-lf-lookup-url="<?= Html::e(AdminUrl::to('taxonomy/lookup')) ?>" defer></script>
<?php endif; ?>

<?php if ($editing): ?>
    <section class="lf-card" aria-labelledby="aliases-heading">
        <h2 id="aliases-heading">Aliases</h2>
        <p class="lf-hint">Other names that lead to this term. A writer who types an alias gets this term.</p>

        <?php if ($aliases !== []): ?>
            <ul class="lf-alias-list">
                <?php foreach ($aliases as $alias): ?>
                    <li>
                        <?= Html::e($alias['alias']) ?>
                        <form method="post" action="<?= Html::e($urls['removeAlias'] . '/' . $alias['id'] . '/remove') ?>" class="lf-inline-form">
                            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                            <button type="submit" class="lf-button lf-button--quiet" aria-label="Remove alias <?= Html::e($alias['alias']) ?>">Remove</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="post" action="<?= Html::e($urls['alias']) ?>" class="lf-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
            <div class="lf-field<?= isset($errors['alias']) ? ' lf-field--error' : '' ?>">
                <label for="alias">Add an alias</label>
                <input type="text" id="alias" name="alias" value="<?= Html::e($values['alias']) ?>" maxlength="100"<?= isset($errors['alias']) ? ' aria-describedby="alias_error"' : '' ?>>
                <?php if (isset($errors['alias'])): ?><p class="lf-error" id="alias_error"><?= Html::e($errors['alias']) ?></p><?php endif; ?>
            </div>
            <p><button type="submit" class="lf-button lf-button--secondary">Add alias</button></p>
        </form>
    </section>

    <?php if (($characters ?? null) !== null): ?>
        <?php $characterLabel = $labels->plural(TermType::Character); ?>
        <section class="lf-card" aria-labelledby="characters-heading">
            <h2 id="characters-heading"><?= Html::e($characterLabel) ?></h2>

            <?php if ($characters === []): ?>
                <p class="lf-hint">No <?= Html::e(mb_strtolower($characterLabel, 'UTF-8')) ?> belong to this fandom yet.</p>
            <?php else: ?>
                <table class="lf-table lf-fandom-characters">
                    <caption class="lf-visually-hidden"><?= Html::e($characterLabel) ?> in this fandom</caption>
                    <thead><tr><th scope="col">Name</th><th scope="col">Stories</th></tr></thead>
                    <tbody>
                        <?php foreach ($characters as $character): ?>
                            <tr>
                                <th scope="row"><a href="<?= Html::e($urls['term'] . '/' . $character->id) ?>"><?= Html::e($character->name) ?></a></th>
                                <td><?= (int) $character->storyCount ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($characterTotal > count($characters)): ?>
                    <p class="lf-hint">Showing the first <?= count($characters) ?> of <?= (int) $characterTotal ?>.</p>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post" action="<?= Html::e($urls['characters']) ?>" class="lf-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                <div class="lf-field<?= isset($errors['characters']) ? ' lf-field--error' : '' ?>">
                    <label for="characters">Add <?= Html::e(mb_strtolower($characterLabel, 'UTF-8')) ?></label>
                    <textarea id="characters" name="characters" rows="5" aria-describedby="characters_hint<?= isset($errors['characters']) ? ' characters_error' : '' ?>"><?= Html::e($values['characters']) ?></textarea>
                    <p class="lf-hint" id="characters_hint">One name per line, up to <?= (int) TaxonomyService::BULK_NAMES_LIMIT ?> at a time. A name this fandom already has, or that exists in another fandom, is reported and not added.</p>
                    <?php if (isset($errors['characters'])): ?><p class="lf-error" id="characters_error"><?= Html::e($errors['characters']) ?></p><?php endif; ?>
                </div>
                <p><button type="submit" class="lf-button lf-button--secondary">Add to this fandom</button></p>
            </form>

            <form method="post" action="<?= Html::e($urls['moveCharacter']) ?>" class="lf-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= Html::e($csrfToken) ?>">
                <div class="lf-field<?= isset($errors['move']) ? ' lf-field--error' : '' ?>">
                    <label for="move_character">Move an existing <?= Html::e($labels->lower(TermType::Character)) ?> here</label>
                    <input type="text" id="move_character" name="move_character" value="<?= Html::e($values['move']) ?>" maxlength="100" autocomplete="off" data-lf-lookup="pick" data-lf-lookup-type="character" aria-describedby="move_hint<?= isset($errors['move']) ? ' move_error' : '' ?>">
                    <p class="lf-hint" id="move_hint">The name of a <?= Html::e($labels->lower(TermType::Character)) ?> that was added under another fandom. Its aliases and stories come with it.</p>
                    <?php if (isset($errors['move'])): ?><p class="lf-error" id="move_error"><?= Html::e($errors['move']) ?></p><?php endif; ?>
                </div>
                <p><button type="submit" class="lf-button lf-button--secondary">Move here</button></p>
            </form>
        </section>
    <?php endif; ?>

    <section class="lf-card" aria-labelledby="tidy-heading">
        <h2 id="tidy-heading">Tidy up</h2>
        <p><a href="<?= Html::e($urls['merge']) ?>">Merge into another <?= Html::e($labels->lower($type)) ?></a> &middot; <a href="<?= Html::e($urls['delete']) ?>">Delete</a></p>
    </section>
<?php endif; ?>
