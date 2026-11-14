<?php

/**
 * The bulk actions on stories that change their details: rating, where a story stands, series, collection, downloads and tags.
 *
 * @package LumoraFiction
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorafiction
 * @source https://github.com/intothisshadow/LumoraFiction
 * @since 1.4.0
 */

declare(strict_types=1);

namespace LumoraFiction\Services;

use LumoraFiction\Core\Admin\BulkResult;
use LumoraFiction\Core\Configuration;
use LumoraFiction\Models\Collection;
use LumoraFiction\Models\DownloadChoice;
use LumoraFiction\Models\Series;
use LumoraFiction\Models\Term;
use LumoraFiction\Models\TermType;
use LumoraFiction\Models\User;
use LumoraFiction\Models\WorkStatus;

/**
 * Every story is changed one at a time through the same service call as the story form, so each is permission-checked
 * on its own, a refused story is skipped and counted, and the audit log gets the same entry as a single change. The
 * publish, unpublish, trash and delete actions stay in StoryService::bulk(). Tags, fandoms and characters follow the
 * story form's rules: a name that is not on the list becomes a proposal only where writers may propose.
 */
final class StoryBulkService
{
    /** @var list<string> */
    public const ACTIONS = ['rating', 'status', 'series', 'collection', 'downloads', 'tag_add', 'tag_remove', 'fandom_add', 'fandom_remove', 'character_add', 'character_remove'];

    /** The kinds a person may add or remove by name, with the action prefix that names each. */
    private const NAMED = ['tag' => TermType::Tag, 'fandom' => TermType::Fandom, 'character' => TermType::Character];

    public function __construct(
        private readonly StoryService $stories,
        private readonly TaxonomyService $taxonomy,
        private readonly AuditLogService $audit,
        private readonly Configuration $config,
        private readonly ?SeriesService $series = null,
        private readonly ?CollectionService $collections = null,
        private readonly ?DownloadAccess $downloads = null,
    ) {
    }

    /**
     * Whether an action is offered on this site: series and collections follow their switches, and a kind of
     * classification follows the archive mode.
     */
    public function offers(string $action): bool
    {
        return match (true) {
            $action === 'series' => $this->series !== null && GroupingSettings::seriesEnabled($this->config),
            $action === 'collection' => $this->collections !== null && GroupingSettings::collectionsEnabled($this->config),
            $action === 'downloads' => $this->downloads !== null && DownloadSettings::enabled($this->config),
            isset(self::NAMED[explode('_', $action)[0]]) && str_contains($action, '_') => $this->taxonomy->isEnabled(self::NAMED[explode('_', $action)[0]]),
            default => in_array($action, self::ACTIONS, true),
        };
    }

    /**
     * What the options page offers for the actions that need a choice.
     *
     * @return array{ratings: list<Term>, statuses: list<WorkStatus>, series: list<Series>, collections: list<Collection>, downloads: list<DownloadChoice>}
     */
    public function choices(User $actor): array
    {
        $collections = [];

        if ($this->collections !== null) {
            foreach ($this->collections->open($actor) as $collection) {
                $collections[$collection->id] = $collection;
            }

            foreach ($this->collections->listFor($actor) as $row) {
                $collections[$row['collection']->id] = $row['collection'];
            }
        }

        return [
            'ratings' => array_values(array_filter($this->taxonomy->list(TermType::Rating, [], 200), static fn (Term $term): bool => !$term->hidden)),
            'statuses' => $this->stories->offeredStatuses(),
            'series' => $this->series === null ? [] : $this->series->ownedBy($actor->id),
            'collections' => array_values($collections),
            'downloads' => DownloadChoice::cases(),
        ];
    }

    /**
     * @param list<int> $storyIds at most BulkResult::LIMIT are handled
     * @param array{rating?: int, status?: string, series?: int, collection?: int, choice?: string, name?: string} $options
     * @throws StoryValidationException when the action is unknown or not offered, or the choice it needs is missing or not valid
     */
    public function apply(User $actor, string $action, array $storyIds, array $options, string $ipAddress = ''): BulkResult
    {
        if (!in_array($action, self::ACTIONS, true) || !$this->offers($action)) {
            throw new StoryValidationException(['bulk_action' => 'Choose what to do with the selected stories.']);
        }

        $change = $this->change($action, $options);
        $result = new BulkResult();

        foreach (array_slice(array_values(array_unique($storyIds)), 0, BulkResult::LIMIT) as $id) {
            try {
                $change($actor, $id, $ipAddress);
                $result->done();
            } catch (StoryPermissionException) {
                $result->skip('Some of the stories could not be changed by you.');
            } catch (GroupingException $exception) {
                $result->skip($exception->getMessage());
            } catch (StoryValidationException $exception) {
                $result->skip(array_values($exception->errors)[0] ?? 'Some of the stories could not be changed.');
            }
        }

        if ($result->doneCount() > 0) {
            $this->audit->record($actor->id, 'story.bulk', 'story', '', ['action' => $action, 'done' => $result->doneCount(), 'skipped' => $result->skippedCount()], $ipAddress);
        }

        return $result;
    }

    /**
     * Checks the choice once, so a bad one fails the whole request before any story is touched, and returns the change
     * to make to each story.
     *
     * @param array{rating?: int, status?: string, series?: int, collection?: int, choice?: string, name?: string} $options
     * @return callable(User, int, string): void
     * @throws StoryValidationException
     */
    private function change(string $action, array $options): callable
    {
        if ($action === 'rating') {
            $rating = $this->taxonomy->find((int) ($options['rating'] ?? 0));

            if ($rating === null || $rating->type !== TermType::Rating || $rating->hidden) {
                throw new StoryValidationException(['rating' => 'Choose one of the ratings listed.']);
            }

            return fn (User $actor, int $id, string $ip): mixed => $this->stories->classify($actor, $id, [TermType::Rating->value => [$rating->id]], $ip);
        }

        if ($action === 'status') {
            $status = WorkStatus::tryFrom($options['status'] ?? '');

            if ($status === null) {
                throw new StoryValidationException(['work_status' => 'Choose where the stories stand.']);
            }

            return fn (User $actor, int $id, string $ip): mixed => $this->stories->update($actor, $id, ['work_status' => $status->value], $ip);
        }

        if ($action === 'series') {
            $series = $this->series?->find((int) ($options['series'] ?? 0));

            if ($series === null) {
                throw new StoryValidationException(['series' => 'Choose one of your series.']);
            }

            return fn (User $actor, int $id, string $ip): mixed => $this->series?->assign($actor, $id, $series->id, null, $ip);
        }

        if ($action === 'collection') {
            $collection = $this->collections?->find((int) ($options['collection'] ?? 0));

            if ($collection === null) {
                throw new StoryValidationException(['collection' => 'Choose one of the collections listed.']);
            }

            // Only the author's own story goes in, so submit() refuses anyone else's however it is reached.
            return fn (User $actor, int $id, string $ip): mixed => $this->collections?->submit($actor, $collection->id, $id, '', $ip);
        }

        if ($action === 'downloads') {
            $choice = DownloadChoice::tryFrom($options['choice'] ?? '');

            if ($choice === null) {
                throw new StoryValidationException(['downloads' => 'Choose one of the options listed.']);
            }

            return fn (User $actor, int $id, string $ip): mixed => $this->downloads?->setChoice($actor, $id, $choice, $ip);
        }

        [$kind, $mode] = explode('_', $action);
        $type = self::NAMED[$kind];

        try {
            $name = $this->taxonomy->cleanName($options['name'] ?? '');
        } catch (TaxonomyException $exception) {
            throw new StoryValidationException(['name' => $exception->getMessage()]);
        }

        return fn (User $actor, int $id, string $ip): mixed => $this->changeNamed($actor, $id, $type, $name, $mode === 'add', $ip);
    }

    /**
     * Adds a term to the story's kind, or takes it away, keeping everything else the story has. The fandoms are carried
     * along so a character is looked up under the story's only fandom, as the story form does.
     *
     * @throws StoryPermissionException
     * @throws StoryValidationException
     */
    private function changeNamed(User $actor, int $storyId, TermType $type, string $name, bool $add, string $ipAddress): void
    {
        $story = $this->stories->manageable($actor, $storyId);
        $current = $this->taxonomy->termsForStory($story->id);
        $key = mb_strtolower($name);
        $kept = array_values(array_filter($current[$type->value] ?? [], static fn (Term $term): bool => $add || mb_strtolower($term->name) !== $key));
        $selection = [];

        if ($type !== TermType::Fandom && $this->taxonomy->isEnabled(TermType::Fandom)) {
            $selection[TermType::Fandom->value] = array_map(static fn (Term $term): int => $term->id, $current[TermType::Fandom->value] ?? []);
        }

        $entries = array_map(static fn (Term $term): int => $term->id, $kept);

        if ($add) {
            $already = array_filter($kept, static fn (Term $term): bool => mb_strtolower($term->name) === $key);

            // Already there under that name: nothing to do for this story.
            if ($already !== []) {
                return;
            }

            $entries[] = $name;
        } elseif (count($kept) === count($current[$type->value] ?? [])) {
            throw new StoryValidationException([$type->value => 'Some of the stories do not have that ' . $this->taxonomy->labels()->lower($type) . '.']);
        }

        $selection[$type->value] = $entries;
        $this->stories->classify($actor, $story->id, $selection, $ipAddress);
    }
}
