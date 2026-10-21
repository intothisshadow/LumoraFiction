<?php

/**
 * Keeps the lists of fandoms, characters, tags, ratings and warnings, and links them to stories.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 0.1.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use DateTimeImmutable;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Core\Database\Database;
use LumoraFiction\Helpers\Slug;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\User;
use PDOException;

/**
 * Curating the lists (renaming, merging, aliases, deleting) belongs to administrators; writers may only
 * pick terms and, for the kinds that allow it, propose new ones while writing. A proposed term is usable
 * at once and is flagged "not yet reviewed" for an administrator to approve or merge. Names are stored as
 * written and escaped by whatever prints them. Public story counts are kept on each term so a listing
 * never has to count; whatever changes a story's visibility asks for them to be refreshed.
 */
final class TaxonomyService
{
    public const NAME_MAX = 100;

    public const DEFAULTS = ['taxonomy.required' => ['rating']];

    /** Control, zero-width and text-direction characters: invisible or reordering, so a name could hide or impersonate behind them. */
    private const FORBIDDEN_CHARACTERS = '/[\p{Cc}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    private const SLUG_BASE_MAX = 100;

    private const SLUG_ATTEMPTS = 5;

    public function __construct(
        private readonly Database $database,
        private readonly Configuration $config,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * The kinds of term the archive mode allows. Kinds that are switched off are hidden, not deleted.
     *
     * @return list<TermType>
     */
    public function enabledTypes(): array
    {
        $mode = $this->config->setting('archive.mode');
        $mode = is_string($mode) && isset(ArchiveSettings::MODES[$mode]) ? $mode : 'both';

        return array_values(array_filter(TermType::cases(), static fn (TermType $type): bool => $type->availableIn($mode)));
    }

    public function isEnabled(TermType $type): bool
    {
        return in_array($type, $this->enabledTypes(), true);
    }

    /**
     * @return list<TermType> the enabled kinds every story must have before it is submitted
     */
    public function requiredTypes(): array
    {
        $stored = $this->config->setting('taxonomy.required');
        $required = [];

        foreach (is_array($stored) ? $stored : [] as $value) {
            $type = is_string($value) ? TermType::tryFrom($value) : null;

            if ($type !== null && $this->isEnabled($type)) {
                $required[$type->value] = $type;
            }
        }

        return array_values($required);
    }

    /**
     * @param list<string> $values kind names; anything unrecognised is ignored
     * @throws TaxonomyException
     */
    public function saveRequiredTypes(User $actor, array $values, string $ipAddress = ''): void
    {
        $this->requireCurator($actor);

        $types = [];

        foreach ($values as $value) {
            $type = TermType::tryFrom($value);

            if ($type !== null) {
                $types[$type->value] = $type->value;
            }
        }

        $new = array_values($types);
        $old = array_map(static fn (TermType $type): string => $type->value, $this->requiredTypes());

        if ($new === $old) {
            return;
        }

        $this->database->transaction(function () use ($actor, $new, $old, $ipAddress): void {
            $this->config->setSetting('taxonomy.required', $new);
            $this->audit->record($actor->id, 'settings.taxonomy.update', 'settings', 'taxonomy', ['from' => $old, 'to' => $new], $ipAddress);
        });
    }

    /**
     * Trims, collapses runs of spaces, and refuses anything that would make a name misleading or unusable.
     *
     * @throws TaxonomyException
     */
    public function cleanName(mixed $value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new TaxonomyException('This contains characters that are not allowed.');
        }

        if (preg_match(self::FORBIDDEN_CHARACTERS, $value) === 1) {
            // Tabs and line breaks are control characters too, but pasting them in is a slip, not an attack.
            $value = (string) preg_replace('/[\t\r\n]+/u', ' ', $value);

            if (preg_match(self::FORBIDDEN_CHARACTERS, $value) === 1) {
                throw new TaxonomyException('This contains characters that are not allowed.');
            }
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($name === '') {
            throw new TaxonomyException('Enter a name.');
        }

        if (mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
            throw new TaxonomyException('Keep the name to ' . self::NAME_MAX . ' characters or fewer.');
        }

        // A comma separates names when several are typed into one box, so it cannot be part of one.
        if (str_contains($name, ',')) {
            throw new TaxonomyException('A name cannot contain a comma.');
        }

        return $name;
    }

    public function find(int $id): ?Term
    {
        $row = $this->database->fetchOne('SELECT * FROM {prefix}terms WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findBySlug(TermType $type, string $slug): ?Term
    {
        $row = $this->database->fetchOne('SELECT * FROM {prefix}terms WHERE type = :type AND slug = :slug', ['type' => $type->value, 'slug' => $slug]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Finds a term by its name or by one of its aliases. The name check is case-insensitive.
     */
    public function resolve(TermType $type, string $name, int $parentId = 0): ?Term
    {
        $key = $this->nameKey($name);

        $row = $this->database->fetchOne(
            'SELECT * FROM {prefix}terms WHERE type = :type AND parent_id = :parent AND name_key = :key',
            ['type' => $type->value, 'parent' => $parentId, 'key' => $key],
        ) ?? $this->database->fetchOne(
            'SELECT t.* FROM {prefix}term_aliases a JOIN {prefix}terms t ON t.id = a.term_id
             WHERE a.type = :type AND a.parent_id = :parent AND a.alias_key = :key',
            ['type' => $type->value, 'parent' => $parentId, 'key' => $key],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return list<array{id: int, alias: string}>
     */
    public function aliasesFor(int $termId): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'alias' => (string) $row['alias']],
            $this->database->fetchAll('SELECT id, alias FROM {prefix}term_aliases WHERE term_id = :id ORDER BY alias_key', ['id' => $termId]),
        );
    }

    /**
     * @param array{search?: string, unreviewed?: bool, parent?: int} $filters
     * @return list<Term>
     */
    public function list(TermType $type, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->filter($type, $filters);

        return $this->hydrateAll($this->database->fetchAll(
            'SELECT t.* FROM {prefix}terms t WHERE ' . $where . ' ORDER BY t.name_key, t.id LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
            $params,
        ));
    }

    /**
     * @param array{search?: string, unreviewed?: bool, parent?: int} $filters
     */
    public function count(TermType $type, array $filters = []): int
    {
        [$where, $params] = $this->filter($type, $filters);

        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}terms t WHERE ' . $where, $params);
    }

    /**
     * @return array<string, array{total: int, unreviewed: int}> keyed by kind name
     */
    public function overview(): array
    {
        $overview = [];

        foreach ($this->database->fetchAll('SELECT type, COUNT(*) AS total, SUM(CASE WHEN reviewed = 0 THEN 1 ELSE 0 END) AS unreviewed FROM {prefix}terms GROUP BY type') as $row) {
            $overview[(string) $row['type']] = ['total' => (int) $row['total'], 'unreviewed' => (int) $row['unreviewed']];
        }

        return $overview;
    }

    /**
     * How many terms each kind lists on its public index. An index lists only reviewed terms that have a public
     * story, going by the stored story count, so a tag that exists only on a draft or that no administrator has
     * reviewed yet is never advertised.
     *
     * @return array<string, int> keyed by kind name
     */
    public function browseCounts(): array
    {
        $counts = [];

        foreach ($this->database->fetchAll('SELECT t.type, COUNT(*) AS total FROM {prefix}terms t WHERE t.reviewed = 1 AND t.story_count > 0 GROUP BY t.type') as $row) {
            $counts[(string) $row['type']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * The first letters an index has terms under, in order, with the first characters behind each. A digit or a
     * symbol is grouped under "#". The grouping follows the column's collation, so "E" and "É" share a group.
     *
     * @return list<array{letter: string, chars: list<string>}>
     */
    public function browseLetters(TermType $type): array
    {
        $groups = [];

        foreach ($this->database->fetchAll(
            'SELECT LEFT(t.name_key, 1) AS c FROM {prefix}terms t WHERE t.type = :type AND t.reviewed = 1 AND t.story_count > 0 GROUP BY LEFT(t.name_key, 1) ORDER BY c LIMIT 200',
            ['type' => $type->value],
        ) as $row) {
            $char = (string) $row['c'];
            $letter = preg_match('/^\p{L}\z/u', $char) === 1 ? mb_strtoupper($char, 'UTF-8') : '#';
            $groups[$letter][] = $char;
        }

        // "#" comes first, as it does in a printed index.
        uksort($groups, static fn (string $a, string $b): int => $a === '#' ? -1 : ($b === '#' ? 1 : 0));

        $letters = [];

        foreach ($groups as $letter => $chars) {
            $letters[] = ['letter' => (string) $letter, 'chars' => $chars];
        }

        return $letters;
    }

    /**
     * One page of an index. $chars limits it to the terms whose first character is one of these (a letter's chars
     * from browseLetters()), or lists every term when empty.
     *
     * @param list<string> $chars
     * @return list<Term>
     */
    public function browseTerms(TermType $type, bool $mostUsed, array $chars, int $limit, int $offset): array
    {
        [$where, $params] = $this->browseCondition($type, $chars);
        $order = $mostUsed ? 't.story_count DESC, t.name_key, t.id' : 't.name_key, t.id';

        return $this->hydrateAll($this->database->fetchAll(
            'SELECT t.* FROM {prefix}terms t WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
            $params,
        ));
    }

    /**
     * @param list<string> $chars
     */
    public function browseTotal(TermType $type, array $chars): int
    {
        [$where, $params] = $this->browseCondition($type, $chars);

        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}terms t WHERE ' . $where, $params);
    }

    /**
     * The most used reviewed characters or relationships of a fandom, for its page to link to.
     *
     * @return list<Term>
     */
    public function relatedTerms(int $fandomId, TermType $type, int $limit): array
    {
        return $this->hydrateAll($this->database->fetchAll(
            'SELECT t.* FROM {prefix}terms t WHERE t.type = :type AND t.parent_id = :parent AND t.reviewed = 1 AND t.story_count > 0
             ORDER BY t.story_count DESC, t.name_key, t.id LIMIT ' . max(1, min(100, $limit)),
            ['type' => $type->value, 'parent' => $fandomId],
        ));
    }

    /**
     * Existing entries whose name or alias contains the typed words, for the story form to offer while a writer
     * types: names that start with the words first, then names that contain them, then aliases. Every entry is
     * offered, reviewed or not, so two writers do not propose the same tag twice; only the name, whether it is
     * reviewed and the alias that matched leave this method, never a count of stories or an author.
     *
     * A character or relationship is narrowed to the fandoms named on the form, plus those that belong to none.
     * Fandoms that do not exist yet narrow nothing, so the list is never empty for a reason the writer cannot see.
     *
     * @param list<string> $skip names already entered in the field
     * @param list<string> $fandomNames the fandoms typed on the form
     * @return list<array{name: string, alias: string, reviewed: bool}>
     */
    public function suggest(TermType $type, string $words, array $skip = [], array $fandomNames = [], int $limit = 8): array
    {
        $key = trim((string) preg_replace('/\s+/u', ' ', $this->nameKey($words)));

        if ($key === '') {
            return [];
        }

        $limit = max(1, min(20, $limit));
        $skipKeys = array_map(fn (string $name): string => $this->nameKey(trim($name)), array_slice($skip, 0, 50));
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $key);
        $params = ['type' => $type->value, 'contains' => '%' . $escaped . '%', 'prefix' => $escaped . '%'];
        $parents = '';

        if ($type->belongsToFandom()) {
            $fandomIds = $this->fandomIdsNamed($fandomNames);

            if ($fandomIds !== []) {
                [$marks, $parentParams] = $this->placeholders(array_values($fandomIds), 'parent');
                $parents = ' AND parent_id IN (0, ' . $marks . ')';
                $params += $parentParams;
            }
        }

        // Skipped names are removed after the query, so it asks for enough rows to still fill the list.
        $rows = max($limit, 1) + count($skipKeys);
        $found = [];
        $seen = [];

        foreach ($this->database->fetchAll(
            'SELECT id, name, name_key, reviewed FROM {prefix}terms WHERE type = :type AND name_key LIKE :contains' . $parents
            . ' ORDER BY CASE WHEN name_key LIKE :prefix THEN 0 ELSE 1 END, story_count DESC, name_key LIMIT ' . $rows,
            $params,
        ) as $row) {
            $seen[(int) $row['id']] = true;

            if (!in_array((string) $row['name_key'], $skipKeys, true)) {
                $found[] = ['name' => (string) $row['name'], 'alias' => '', 'reviewed' => (int) $row['reviewed'] === 1];
            }
        }

        if (count($found) < $limit) {
            $aliasParams = ['type' => $type->value, 'contains' => $params['contains']] + array_diff_key($params, ['type' => 1, 'contains' => 1, 'prefix' => 1]);

            foreach ($this->database->fetchAll(
                'SELECT a.alias, t.id, t.name, t.name_key, t.reviewed FROM {prefix}term_aliases a JOIN {prefix}terms t ON t.id = a.term_id
                 WHERE a.type = :type AND a.alias_key LIKE :contains' . str_replace('parent_id', 't.parent_id', $parents)
                . ' ORDER BY a.alias_key LIMIT ' . $rows,
                $aliasParams,
            ) as $row) {
                // The same entry reached by its name and by an alias is offered once, under its name.
                if (isset($seen[(int) $row['id']]) || in_array((string) $row['name_key'], $skipKeys, true)) {
                    continue;
                }

                $seen[(int) $row['id']] = true;
                $found[] = ['name' => (string) $row['name'], 'alias' => (string) $row['alias'], 'reviewed' => (int) $row['reviewed'] === 1];
            }
        }

        return array_slice($found, 0, $limit);
    }

    /**
     * The ids of the fandoms with these names or aliases, in one query.
     *
     * @param list<string> $names
     * @return list<int>
     */
    private function fandomIdsNamed(array $names): array
    {
        $keys = [];

        foreach (array_slice($names, 0, 10) as $name) {
            $key = $this->nameKey(trim($name));

            if ($key !== '' && mb_strlen($key, 'UTF-8') <= 100) {
                $keys[$key] = $key;
            }
        }

        if ($keys === []) {
            return [];
        }

        // A named placeholder is used once, so the same keys are bound again under other names for the alias check.
        $params = ['type' => TermType::Fandom->value, 'alias_type' => TermType::Fandom->value];
        $names = [];
        $aliases = [];

        foreach (array_values($keys) as $index => $key) {
            $names[] = ':name' . $index;
            $aliases[] = ':alias' . $index;
            $params['name' . $index] = $key;
            $params['alias' . $index] = $key;
        }

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->fetchAll(
                'SELECT t.id FROM {prefix}terms t WHERE t.type = :type AND (t.name_key IN (' . implode(', ', $names) . ') OR t.id IN (SELECT a.term_id FROM {prefix}term_aliases a WHERE a.type = :alias_type AND a.alias_key IN (' . implode(', ', $aliases) . ')))',
                $params,
            ),
        );
    }

    /**
     * @param list<string> $chars
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function browseCondition(TermType $type, array $chars): array
    {
        $where = 't.type = :type AND t.reviewed = 1 AND t.story_count > 0';
        $params = ['type' => $type->value];

        if ($chars !== []) {
            $marks = [];

            foreach (array_values($chars) as $index => $char) {
                $marks[] = ':char' . $index;
                $params['char' . $index] = $char;
            }

            $where .= ' AND LEFT(t.name_key, 1) IN (' . implode(', ', $marks) . ')';
        }

        return [$where, $params];
    }

    /**
     * @param list<int> $ids
     * @return array<int, string> term id => name
     */
    public function namesFor(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        [$marks, $params] = $this->placeholders($ids, 'id');
        $names = [];

        foreach ($this->database->fetchAll('SELECT id, name FROM {prefix}terms WHERE id IN (' . $marks . ')', $params) as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        return $names;
    }

    /**
     * An administrator adds a term directly, already reviewed.
     *
     * @throws TaxonomyException
     */
    public function create(User $actor, TermType $type, string $name, int $parentId = 0, string $ipAddress = ''): Term
    {
        $this->requireCurator($actor);

        $name = $this->cleanName($name);
        $this->assertParent($type, $parentId);
        $this->assertNameFree($type, $parentId, $name, null);

        $id = (int) $this->database->transaction(function () use ($actor, $type, $parentId, $name, $ipAddress): int {
            $id = $this->insertTerm($type, $parentId, $name, true, $actor->id);
            $this->audit->record($actor->id, 'term.create', 'term', (string) $id, ['type' => $type->value, 'name' => $name], $ipAddress);

            return $id;
        });

        return $this->mustFind($id);
    }

    /**
     * Changes the canonical name. Choosing one of the term's own aliases swaps the two, so the old name keeps
     * leading here. The address of the term does not change.
     *
     * @throws TaxonomyException
     */
    public function rename(User $actor, int $termId, string $name, string $ipAddress = ''): Term
    {
        $this->requireCurator($actor);

        $term = $this->mustFind($termId);
        $name = $this->cleanName($name);
        $key = $this->nameKey($name);
        $oldKey = $this->nameKey($term->name);

        if ($name === $term->name) {
            return $term;
        }

        $ownAlias = null;

        if ($key !== $oldKey) {
            $ownAlias = $this->database->fetchOne(
                'SELECT id FROM {prefix}term_aliases WHERE term_id = :term AND alias_key = :key',
                ['term' => $term->id, 'key' => $key],
            );

            if ($ownAlias === null) {
                $this->assertNameFree($term->type, $term->parentId, $name, $term->id);
            }
        }

        try {
            $this->database->transaction(function () use ($actor, $term, $name, $key, $oldKey, $ownAlias, $ipAddress): void {
                if ($ownAlias !== null) {
                    $this->database->execute('DELETE FROM {prefix}term_aliases WHERE id = :id', ['id' => (int) $ownAlias['id']]);
                }

                $this->database->execute('UPDATE {prefix}terms SET name = :name, name_key = :key WHERE id = :id', ['name' => $name, 'key' => $key, 'id' => $term->id]);

                if ($ownAlias !== null) {
                    $this->insertAlias($term->id, $term->type, $term->parentId, $term->name, $oldKey);
                }

                $this->audit->record($actor->id, 'term.rename', 'term', (string) $term->id, ['from' => $term->name, 'to' => $name], $ipAddress);
            });
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw new TaxonomyException('There is already a term with that name.', previous: $exception);
            }

            throw $exception;
        }

        return $this->mustFind($term->id);
    }

    /**
     * @throws TaxonomyException
     */
    public function setReviewed(User $actor, int $termId, bool $reviewed, string $ipAddress = ''): Term
    {
        $this->requireCurator($actor);

        $term = $this->mustFind($termId);

        if ($term->reviewed !== $reviewed) {
            $this->database->transaction(function () use ($actor, $term, $reviewed, $ipAddress): void {
                $this->database->execute('UPDATE {prefix}terms SET reviewed = :reviewed WHERE id = :id', ['reviewed' => $reviewed ? 1 : 0, 'id' => $term->id]);
                $this->audit->record($actor->id, 'term.review', 'term', (string) $term->id, ['reviewed' => $reviewed], $ipAddress);
            });
        }

        return $this->mustFind($term->id);
    }

    /**
     * @throws TaxonomyException
     */
    public function addAlias(User $actor, int $termId, string $alias, string $ipAddress = ''): void
    {
        $this->requireCurator($actor);

        $term = $this->mustFind($termId);
        $alias = $this->cleanName($alias);

        $this->assertNameFree($term->type, $term->parentId, $alias, $term->id, true);

        try {
            $this->database->transaction(function () use ($actor, $term, $alias, $ipAddress): void {
                $this->insertAlias($term->id, $term->type, $term->parentId, $alias, $this->nameKey($alias));
                $this->audit->record($actor->id, 'term.alias_add', 'term', (string) $term->id, ['alias' => $alias], $ipAddress);
            });
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw new TaxonomyException('That name already leads somewhere.', previous: $exception);
            }

            throw $exception;
        }
    }

    /**
     * @throws TaxonomyException
     */
    public function removeAlias(User $actor, int $aliasId, string $ipAddress = ''): ?Term
    {
        $this->requireCurator($actor);

        $row = $this->database->fetchOne('SELECT * FROM {prefix}term_aliases WHERE id = :id', ['id' => $aliasId]);

        if ($row === null) {
            throw new TaxonomyException('That alias no longer exists.');
        }

        $this->database->transaction(function () use ($actor, $row, $aliasId, $ipAddress): void {
            $this->database->execute('DELETE FROM {prefix}term_aliases WHERE id = :id', ['id' => $aliasId]);
            $this->audit->record($actor->id, 'term.alias_remove', 'term', (string) $row['term_id'], ['alias' => (string) $row['alias']], $ipAddress);
        });

        return $this->find((int) $row['term_id']);
    }

    /**
     * Moves every story, alias and (for a fandom) character and relationship from $sourceId to $targetId, keeps
     * the source's name as an alias of the target, and removes the source.
     *
     * @throws TaxonomyException
     */
    public function merge(User $actor, int $sourceId, int $targetId, string $ipAddress = ''): Term
    {
        $this->requireCurator($actor);

        $source = $this->mustFind($sourceId);
        $target = $this->mustFind($targetId);

        if ($source->id === $target->id) {
            throw new TaxonomyException('Choose a different term to merge into.');
        }

        if ($source->type !== $target->type) {
            throw new TaxonomyException('Only terms of the same kind can be merged.');
        }

        if ($source->parentId !== $target->parentId) {
            throw new TaxonomyException('These belong to different fandoms, so they cannot be merged.');
        }

        $this->database->transaction(function () use ($actor, $source, $target, $ipAddress): void {
            $this->absorb($source, $target);
            $this->audit->record($actor->id, 'term.merge', 'term', (string) $target->id, ['from' => $source->name, 'into' => $target->name, 'type' => $source->type->value], $ipAddress);
        });

        return $this->mustFind($target->id);
    }

    /**
     * Only a term nothing uses can be deleted; otherwise it has to be merged so no story loses its classification.
     *
     * @throws TaxonomyException
     */
    public function delete(User $actor, int $termId, string $ipAddress = ''): void
    {
        $this->requireCurator($actor);

        $term = $this->mustFind($termId);

        if ((int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}story_terms WHERE term_id = :id', ['id' => $term->id]) > 0) {
            throw new TaxonomyException('Stories still use this term. Merge it into another term instead of deleting it.');
        }

        if ((int) $this->database->fetchColumn('SELECT COUNT(*) FROM {prefix}terms WHERE parent_id = :id', ['id' => $term->id]) > 0) {
            throw new TaxonomyException('Characters or relationships still belong to this fandom. Merge or delete them first.');
        }

        $this->database->transaction(function () use ($actor, $term, $ipAddress): void {
            $this->database->execute('DELETE FROM {prefix}term_aliases WHERE term_id = :id', ['id' => $term->id]);
            $this->database->execute('DELETE FROM {prefix}terms WHERE id = :id', ['id' => $term->id]);
            $this->audit->record($actor->id, 'term.delete', 'term', (string) $term->id, ['type' => $term->type->value, 'name' => $term->name], $ipAddress);
        });
    }

    /**
     * @return array<string, list<Term>> the story's terms grouped by kind name, in the order kinds are declared
     */
    public function termsForStory(int $storyId): array
    {
        return $this->termsForStories([$storyId])[$storyId] ?? [];
    }

    /**
     * One query for a whole page of stories, so a listing does not ask once per story.
     *
     * @param list<int> $storyIds
     * @return array<int, array<string, list<Term>>> story id => kind name => terms
     */
    public function termsForStories(array $storyIds): array
    {
        $storyIds = array_values(array_unique($storyIds));

        if ($storyIds === []) {
            return [];
        }

        [$marks, $params] = $this->placeholders($storyIds, 'story');
        $found = [];

        $rows = $this->database->fetchAll(
            'SELECT t.*, st.story_id FROM {prefix}story_terms st JOIN {prefix}terms t ON t.id = st.term_id
             WHERE st.story_id IN (' . $marks . ') ORDER BY t.name_key, t.id',
            $params,
        );

        foreach ($rows as $row) {
            $term = $this->hydrate($row);
            $found[(int) $row['story_id']][$term->type->value][] = $term;
        }

        $grouped = [];

        foreach ($found as $storyId => $byType) {
            foreach (TermType::cases() as $type) {
                if (isset($byType[$type->value])) {
                    $grouped[$storyId][$type->value] = $byType[$type->value];
                }
            }
        }

        return $grouped;
    }

    /**
     * Sets a story's terms for the kinds present in $selection; kinds left out, and kinds the archive mode
     * has switched off, keep what they have. A whole number is the id of an existing term and text is a name:
     * a name that matches a term (or an alias) uses it, and otherwise becomes a new, unreviewed term when
     * that kind lets writers propose. The caller has already checked that the actor may change the story, and
     * runs this inside a transaction so a refused selection leaves no half-made terms behind.
     *
     * @param array<string, list<int|string>> $selection kind name => ids or names
     * @throws StoryValidationException
     */
    public function assignToStory(User $actor, int $storyId, array $selection, string $ipAddress = ''): void
    {
        $errors = [];
        $desired = [];

        foreach ($this->enabledTypes() as $type) {
            if (!array_key_exists($type->value, $selection)) {
                continue;
            }

            $entries = $selection[$type->value];

            if (!is_array($entries)) {
                $errors[$type->value] = 'This selection is not valid.';

                continue;
            }

            $fandoms = $desired[TermType::Fandom->value] ?? [];
            $parent = $type->belongsToFandom() && count($fandoms) === 1 ? $fandoms[0] : 0;
            $ids = [];

            foreach ($entries as $entry) {
                $term = $this->selected($actor, $type, $entry, $parent, $ipAddress, $errors);

                if ($term !== null) {
                    $ids[$term->id] = $term->id;
                }
            }

            if (count($ids) > $type->maximumPerStory()) {
                $errors[$type->value] = $type->isSingleChoice()
                    ? 'Choose only one ' . strtolower($type->label()) . '.'
                    : 'Choose no more than ' . $type->maximumPerStory() . ' ' . strtolower($type->pluralLabel()) . '.';
            }

            $desired[$type->value] = array_values($ids);
        }

        if ($errors !== []) {
            throw new StoryValidationException($errors);
        }

        $this->applyLinks($actor, $storyId, $desired, $ipAddress);
    }

    /**
     * What is still missing for the story to meet the administrator's required kinds. A kind whose list is
     * empty and cannot be added to by writers is never required, since nothing could satisfy it.
     *
     * @return array<string, string> kind name => problem
     */
    public function missingRequired(int $storyId): array
    {
        $missing = [];

        foreach ($this->requiredTypes() as $type) {
            $linked = (int) $this->database->fetchColumn(
                'SELECT COUNT(*) FROM {prefix}story_terms st JOIN {prefix}terms t ON t.id = st.term_id WHERE st.story_id = :story AND t.type = :type',
                ['story' => $storyId, 'type' => $type->value],
            );

            if ($linked > 0) {
                continue;
            }

            if (!$type->writersMayPropose() && $this->count($type) === 0) {
                continue;
            }

            $missing[$type->value] = $type->isSingleChoice()
                ? 'Choose a ' . strtolower($type->label()) . '.'
                : 'Add at least one ' . strtolower($type->label()) . '.';
        }

        return $missing;
    }

    /**
     * Removes a story's links and brings the affected counts back in step. Called when the story is deleted.
     */
    public function removeStoryLinks(int $storyId): void
    {
        $ids = $this->linkedTermIds($storyId);

        $this->database->execute('DELETE FROM {prefix}story_terms WHERE story_id = :story', ['story' => $storyId]);
        $this->refreshCountsForTerms($ids);
    }

    /**
     * Recounts the public stories of every term the story carries. Call after anything that changes whether
     * the story is public.
     */
    public function refreshCountsForStory(int $storyId): void
    {
        $this->refreshCountsForTerms($this->linkedTermIds($storyId));
    }

    /**
     * @param list<int> $termIds
     */
    public function refreshCountsForTerms(array $termIds): void
    {
        $termIds = array_values(array_unique($termIds));

        if ($termIds === []) {
            return;
        }

        [$marks, $params] = $this->placeholders($termIds, 'term');

        $this->database->execute($this->countUpdate() . ' WHERE t.id IN (' . $marks . ')', $params);
    }

    /**
     * Recounts every term; for repairing the counts after a bulk change made outside the application.
     */
    public function refreshAllCounts(): void
    {
        $this->database->execute($this->countUpdate());
    }

    private function countUpdate(): string
    {
        return 'UPDATE {prefix}terms t SET t.story_count = (
                    SELECT COUNT(*) FROM {prefix}story_terms st JOIN {prefix}stories s ON s.id = st.story_id
                    WHERE st.term_id = t.id AND ' . StoryVisibility::publicCondition('s') . ')';
    }

    /**
     * @return list<int>
     */
    private function linkedTermIds(int $storyId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['term_id'],
            $this->database->fetchAll('SELECT term_id FROM {prefix}story_terms WHERE story_id = :story', ['story' => $storyId]),
        );
    }

    /**
     * @param array<string, string> $errors
     */
    private function selected(User $actor, TermType $type, mixed $entry, int $parent, string $ipAddress, array &$errors): ?Term
    {
        if (is_int($entry)) {
            $term = $this->find($entry);

            if ($term === null || $term->type !== $type) {
                $errors[$type->value] = 'One of the ' . strtolower($type->pluralLabel()) . ' you chose no longer exists.';

                return null;
            }

            return $term;
        }

        try {
            $name = $this->cleanName($entry);
        } catch (TaxonomyException $exception) {
            $errors[$type->value] = $exception->getMessage();

            return null;
        }

        $term = $this->resolve($type, $name, $parent) ?? ($parent !== 0 ? $this->resolve($type, $name, 0) : null);

        if ($term !== null) {
            return $term;
        }

        if (!$type->writersMayPropose() || !$actor->can(Capability::WriteStories)) {
            $errors[$type->value] = '"' . $name . '" is not on the list of ' . strtolower($type->pluralLabel()) . '. Choose one from the list.';

            return null;
        }

        try {
            return $this->propose($actor, $type, $name, $parent, $ipAddress);
        } catch (TaxonomyException $exception) {
            $errors[$type->value] = $exception->getMessage();

            return null;
        }
    }

    private function propose(User $actor, TermType $type, string $name, int $parentId, string $ipAddress): Term
    {
        $id = $this->insertTerm($type, $parentId, $name, false, $actor->id);
        $this->audit->record($actor->id, 'term.propose', 'term', (string) $id, ['type' => $type->value, 'name' => $name], $ipAddress);

        return $this->mustFind($id);
    }

    /**
     * @param array<string, list<int>> $desired kind name => term ids that should be linked
     */
    private function applyLinks(User $actor, int $storyId, array $desired, string $ipAddress): void
    {
        $affected = [];
        $changed = [];

        foreach ($desired as $typeName => $wanted) {
            $current = array_map(
                static fn (array $row): int => (int) $row['term_id'],
                $this->database->fetchAll(
                    'SELECT st.term_id FROM {prefix}story_terms st JOIN {prefix}terms t ON t.id = st.term_id WHERE st.story_id = :story AND t.type = :type',
                    ['story' => $storyId, 'type' => $typeName],
                ),
            );

            $remove = array_diff($current, $wanted);
            $add = array_diff($wanted, $current);

            foreach ($remove as $termId) {
                $this->database->execute('DELETE FROM {prefix}story_terms WHERE story_id = :story AND term_id = :term', ['story' => $storyId, 'term' => $termId]);
            }

            foreach ($add as $termId) {
                $this->database->execute('INSERT INTO {prefix}story_terms (story_id, term_id) VALUES (:story, :term)', ['story' => $storyId, 'term' => $termId]);
            }

            if ($remove !== [] || $add !== []) {
                $changed[] = $typeName;
                $affected = array_merge($affected, $remove, $add);
            }
        }

        if ($changed !== []) {
            $this->refreshCountsForTerms($affected);
            $this->audit->record($actor->id, 'story.classify', 'story', (string) $storyId, ['types' => $changed], $ipAddress);
        }
    }

    /**
     * Folds $source into $target without checking the two are alike; merge() does that. Recurses into the
     * characters and relationships of a fandom, which are merged one by one when the target already has one
     * of the same name and simply moved otherwise.
     */
    private function absorb(Term $source, Term $target): void
    {
        $children = $this->database->fetchAll('SELECT * FROM {prefix}terms WHERE parent_id = :id', ['id' => $source->id]);

        foreach ($children as $row) {
            $child = $this->hydrate($row);
            $match = $this->database->fetchOne(
                'SELECT * FROM {prefix}terms WHERE type = :type AND parent_id = :parent AND name_key = :key',
                ['type' => $child->type->value, 'parent' => $target->id, 'key' => $this->nameKey($child->name)],
            );

            if ($match !== null) {
                $this->absorb($child, $this->hydrate($match));

                continue;
            }

            $this->database->execute('UPDATE {prefix}terms SET parent_id = :parent WHERE id = :id', ['parent' => $target->id, 'id' => $child->id]);
            // An alias that would now clash with one already there is redundant and is dropped.
            $this->database->execute('UPDATE IGNORE {prefix}term_aliases SET parent_id = :parent WHERE term_id = :id', ['parent' => $target->id, 'id' => $child->id]);
            $this->database->execute('DELETE FROM {prefix}term_aliases WHERE term_id = :id AND parent_id <> :parent', ['id' => $child->id, 'parent' => $target->id]);
        }

        $this->database->execute(
            'INSERT IGNORE INTO {prefix}story_terms (story_id, term_id) SELECT story_id, :target FROM {prefix}story_terms WHERE term_id = :source',
            ['target' => $target->id, 'source' => $source->id],
        );
        $this->database->execute('DELETE FROM {prefix}story_terms WHERE term_id = :source', ['source' => $source->id]);

        $this->database->execute(
            'UPDATE IGNORE {prefix}term_aliases SET term_id = :target, parent_id = :parent WHERE term_id = :source',
            ['target' => $target->id, 'parent' => $target->parentId, 'source' => $source->id],
        );
        $this->database->execute('DELETE FROM {prefix}term_aliases WHERE term_id = :source', ['source' => $source->id]);
        $this->database->execute('DELETE FROM {prefix}terms WHERE id = :source', ['source' => $source->id]);

        // The merged name keeps leading to the survivor, so anyone who types it still finds the right term.
        // A name that differs only by case already does, and needs no alias.
        if ($this->nameKey($source->name) !== $this->nameKey($target->name)) {
            $this->database->execute(
                'INSERT IGNORE INTO {prefix}term_aliases (term_id, type, parent_id, alias, alias_key, created_at) VALUES (:term, :type, :parent, :alias, :key, :created)',
                [
                    'term' => $target->id,
                    'type' => $target->type->value,
                    'parent' => $target->parentId,
                    'alias' => $source->name,
                    'key' => $this->nameKey($source->name),
                    'created' => date('Y-m-d H:i:s'),
                ],
            );
        }

        $this->refreshCountsForTerms([$target->id]);
    }

    /**
     * @param array{search?: string, unreviewed?: bool, parent?: int} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function filter(TermType $type, array $filters): array
    {
        $where = ['t.type = :type'];
        $params = ['type' => $type->value];

        if (isset($filters['parent'])) {
            $where[] = 't.parent_id = :parent';
            $params['parent'] = $filters['parent'];
        }

        if (($filters['unreviewed'] ?? false) === true) {
            $where[] = 't.reviewed = 0';
        }

        $search = trim($filters['search'] ?? '');

        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->nameKey($search)) . '%';
            $where[] = '(t.name_key LIKE :search_name OR EXISTS (SELECT 1 FROM {prefix}term_aliases fa WHERE fa.term_id = t.id AND fa.alias_key LIKE :search_alias))';
            $params['search_name'] = $like;
            $params['search_alias'] = $like;
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @throws TaxonomyException
     */
    private function requireCurator(User $actor): void
    {
        if (!$actor->can(Capability::ManageSite)) {
            throw new TaxonomyException('You may not change the classification lists.');
        }
    }

    /**
     * @throws TaxonomyException
     */
    private function assertParent(TermType $type, int $parentId): void
    {
        if ($parentId === 0) {
            return;
        }

        $parent = $type->belongsToFandom() ? $this->find($parentId) : null;

        if ($parent === null || $parent->type !== TermType::Fandom) {
            throw new TaxonomyException('Choose one of the fandoms on the list.');
        }
    }

    /**
     * Refuses a name that another term, or another term's alias, already uses in the same list.
     *
     * @throws TaxonomyException
     */
    private function assertNameFree(TermType $type, int $parentId, string $name, ?int $exceptTermId, bool $isAlias = false): void
    {
        $existing = $this->resolve($type, $name, $parentId);

        if ($existing !== null && $existing->id !== $exceptTermId) {
            throw new TaxonomyException($this->nameKey($existing->name) === $this->nameKey($name)
                ? 'There is already a ' . strtolower($type->label()) . ' called "' . $existing->name . '".'
                : '"' . $name . '" is already an alias of "' . $existing->name . '".');
        }

        if ($isAlias && $existing !== null && $existing->id === $exceptTermId) {
            throw new TaxonomyException('That is already a name for this term.');
        }
    }

    private function insertTerm(TermType $type, int $parentId, string $name, bool $reviewed, ?int $createdBy): int
    {
        $base = Slug::fromText($name, self::SLUG_BASE_MAX, 'term');

        for ($attempt = 0; $attempt < self::SLUG_ATTEMPTS; $attempt++) {
            $slug = $attempt === 0 ? $this->freeSlug($type, $base) : $base . '-' . bin2hex(random_bytes(3));

            try {
                return (int) $this->database->insertGetId(
                    'INSERT INTO {prefix}terms (type, parent_id, name, name_key, slug, reviewed, story_count, created_by, created_at)
                     VALUES (:type, :parent, :name, :key, :slug, :reviewed, 0, :creator, :created)',
                    [
                        'type' => $type->value,
                        'parent' => $parentId,
                        'name' => $name,
                        'key' => $this->nameKey($name),
                        'slug' => $slug,
                        'reviewed' => $reviewed ? 1 : 0,
                        'creator' => $createdBy,
                        'created' => date('Y-m-d H:i:s'),
                    ],
                );
            } catch (PDOException $exception) {
                if (($exception->errorInfo[1] ?? null) !== 1062) {
                    throw $exception;
                }

                // A duplicate name means someone added it a moment ago; a duplicate address just needs another suffix.
                if ($this->resolve($type, $name, $parentId) !== null) {
                    throw new TaxonomyException('There is already a ' . strtolower($type->label()) . ' with that name.', previous: $exception);
                }
            }
        }

        throw new PDOException('Could not find a free address for the term.');
    }

    private function freeSlug(TermType $type, string $base): string
    {
        for ($number = 1; $number < 1000; $number++) {
            $candidate = $number === 1 ? $base : $base . '-' . $number;

            if ($this->findBySlug($type, $candidate) === null) {
                return $candidate;
            }
        }

        return $base . '-' . bin2hex(random_bytes(4));
    }

    private function insertAlias(int $termId, TermType $type, int $parentId, string $alias, string $key): void
    {
        $this->database->execute(
            'INSERT INTO {prefix}term_aliases (term_id, type, parent_id, alias, alias_key, created_at) VALUES (:term, :type, :parent, :alias, :key, :created)',
            ['term' => $termId, 'type' => $type->value, 'parent' => $parentId, 'alias' => $alias, 'key' => $key, 'created' => date('Y-m-d H:i:s')],
        );
    }

    /**
     * Names are matched case-insensitively, so the stored comparison key is the lower-cased name.
     */
    private function nameKey(string $name): string
    {
        return mb_strtolower($name, 'UTF-8');
    }

    /**
     * @param list<int> $values
     * @return array{0: string, 1: array<string, int>}
     */
    private function placeholders(array $values, string $prefix): array
    {
        $params = [];

        foreach (array_values($values) as $index => $value) {
            $params[$prefix . $index] = $value;
        }

        return [implode(', ', array_map(static fn (string $key): string => ':' . $key, array_keys($params))), $params];
    }

    private function mustFind(int $id): Term
    {
        return $this->find($id) ?? throw new TaxonomyException('That term no longer exists.');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Term>
     */
    private function hydrateAll(array $rows): array
    {
        return array_map(fn (array $row): Term => $this->hydrate($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Term
    {
        return new Term(
            id: (int) $row['id'],
            // A row of an unrecognised kind cannot be shown or linked, so it is treated as a tag rather than failing the page.
            type: TermType::tryFrom((string) $row['type']) ?? TermType::Tag,
            parentId: (int) $row['parent_id'],
            name: (string) $row['name'],
            slug: (string) $row['slug'],
            reviewed: (int) $row['reviewed'] === 1,
            storyCount: (int) $row['story_count'],
            createdBy: $row['created_by'] === null ? null : (int) $row['created_by'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
