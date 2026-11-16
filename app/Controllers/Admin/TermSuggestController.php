<?php

/**
 * The request the story form makes while a writer types a fandom, character, relationship or tag: which existing entries match.
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

namespace LumoraFiction\Controllers\Admin;

use LumoraFiction\Core\Admin\AdminUrl;
use LumoraFiction\Core\Http\Request;
use LumoraFiction\Core\Http\Response;
use LumoraFiction\Models\Capability;
use LumoraFiction\Models\TermType;
use LumoraFiction\Services\AuthService;
use LumoraFiction\Services\TaxonomyException;
use LumoraFiction\Services\TaxonomyService;

/**
 * A script request, so it answers in JSON and never redirects. It only reads, so it needs no form token, but it
 * still needs a signed-in writer: the list of names is for people who are classifying a story.
 */
final class TermSuggestController
{
    private const MIN_WORDS = 2;

    private const MAX_WORDS = 100;

    public function __construct(
        private readonly AuthService $auth,
        private readonly TaxonomyService $taxonomy,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function suggest(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->json(['ok' => false, 'message' => 'Your session has ended.'], 401);
        }

        if (!$user->can(Capability::WriteStories)) {
            return $this->json(['ok' => false, 'message' => 'You may not do that.'], 403);
        }

        $type = TermType::tryFrom($request->queryValue('type') ?? '');

        // Only the kinds a writer types by name, and only those the archive mode has not switched off.
        if ($type === null || !$this->taxonomy->writersMayPropose($type) || !$this->taxonomy->isEnabled($type)) {
            return $this->json(['ok' => false, 'message' => 'That kind cannot be suggested.'], 422);
        }

        $words = trim($request->queryValue('q') ?? '');
        $length = mb_strlen($words, 'UTF-8');

        if ($length < self::MIN_WORDS || $length > self::MAX_WORDS) {
            return $this->json(['ok' => true, 'suggestions' => []]);
        }

        return $this->json([
            'ok' => true,
            'suggestions' => $this->taxonomy->suggest($type, $words, $this->names($request->queryValue('skip')), $this->names($request->queryValue('fandoms'))),
        ]);
    }

    /**
     * What already exists, for an administrator's merge and create-term screens. On the merge screen the term being
     * merged names itself (`merging`), so the lookup takes its kind and fandom from the stored term and never offers it;
     * on the create screen the kind and the fandom typed so far come from the address. Only a signed-in administrator
     * is answered, since unapproved and hidden terms are included.
     *
     * @param array<string, string> $params
     */
    public function lookup(array $params, Request $request): Response
    {
        $user = $this->auth->user($request->cookies);

        if ($user === null) {
            return $this->json(['ok' => false, 'message' => 'Your session has ended.'], 401);
        }

        if (!$user->can(Capability::ManageSite)) {
            return $this->json(['ok' => false, 'message' => 'You may not do that.'], 403);
        }

        $words = trim($request->queryValue('q') ?? '');
        $length = mb_strlen($words, 'UTF-8');
        $merging = $request->queryValue('merging') ?? '';
        $exclude = 0;
        $parent = null;

        if ($merging !== '') {
            $term = preg_match('/^[1-9][0-9]{0,9}\z/', $merging) === 1 ? $this->taxonomy->find((int) $merging) : null;

            if ($term === null) {
                return $this->json(['ok' => false, 'message' => 'That term no longer exists.'], 404);
            }

            $type = $term->type;
            $exclude = $term->id;
            $parent = $type->belongsToFandom() ? $term->parentId : null;
        } else {
            $type = TermType::tryFrom($request->queryValue('type') ?? '');

            if ($type === null || !$this->taxonomy->isEnabled($type)) {
                return $this->json(['ok' => false, 'message' => 'That kind cannot be looked up.'], 422);
            }

            $fandom = trim($request->queryValue('fandom') ?? '');

            if ($type->belongsToFandom()) {
                // No fandom typed means a term that belongs to none; a fandom that does not exist matches nothing.
                $parent = 0;

                if ($fandom !== '') {
                    try {
                        $parent = $this->taxonomy->resolve(TermType::Fandom, $this->taxonomy->cleanName($fandom))?->id;
                    } catch (TaxonomyException) {
                        $parent = null;
                    }

                    if ($parent === null) {
                        return $this->json(['ok' => true, 'matches' => []]);
                    }
                }
            }
        }

        if ($length < self::MIN_WORDS || $length > self::MAX_WORDS) {
            return $this->json(['ok' => true, 'matches' => []]);
        }

        $matches = [];

        foreach ($this->taxonomy->lookup($type, $words, $parent, $exclude, 10) as $match) {
            $matches[] = $match + ['kind' => $type->value, 'url' => AdminUrl::to('taxonomy/term/' . $match['id'])];
        }

        return $this->json(['ok' => true, 'matches' => $matches]);
    }

    /**
     * A comma-separated list from the address, without empty or overlong entries.
     *
     * @return list<string>
     */
    private function names(?string $list): array
    {
        $names = [];

        foreach (explode(',', (string) $list) as $name) {
            $name = trim($name);

            if ($name !== '' && mb_strlen($name, 'UTF-8') <= self::MAX_WORDS) {
                $names[] = $name;
            }
        }

        return array_slice($names, 0, 50);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): Response
    {
        return new Response(
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow'],
        );
    }
}
