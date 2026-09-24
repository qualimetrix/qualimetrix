<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication;

use Qualimetrix\Core\Path\RelativePath;

/**
 * Verifies hash-bucket matches, extends them into full duplicate blocks,
 * and applies the data-table / self-duplication filters.
 *
 * This is pass 2's second half — it runs only on the small set of files
 * {@see HashIndexBuildResult::neededFileIndices()} flagged, and only on the
 * hash buckets that survived pruning, so it never touches the full token
 * stream of every file at once (see {@see DuplicationDetector} for the
 * memory-optimization rationale this split preserves).
 *
 * Every occurrence of one token sequence is evaluated as one group and
 * reported as one {@see DuplicateBlock} carrying all of its locations. Work
 * and retained blocks therefore grow linearly with the number of copies:
 * comparing copies pairwise grows quadratically and, because every window
 * offset of a copied block is its own bucket, retains a block per pair per
 * offset — 99 copies of one 150-token class exhausted a 128M limit.
 *
 * A group whose members all share the preceding token is skipped: the
 * bucket of that preceding window holds the same members one token
 * earlier and yields a block that contains this one.
 *
 * A match is held in {@see DuplicateMatchCandidates} until every match
 * whose copies all lie inside a longer one is dropped; only the survivors
 * become {@see DuplicateBlock}s.
 *
 * {@see find()} holds the current request/scratch state as instance
 * properties for the duration of one call so the nested helpers below
 * don't have to thread unchanging values through every signature — the
 * same pattern {@see DuplicationDetector} itself uses for its rule options.
 * This class is not reentrant; a single find() call must complete before
 * another begins (true for all current callers).
 *
 * find() clears these properties before returning. This instance is a
 * long-lived field of {@see DuplicationDetector} (constructed once, reused
 * across every inspect() call), so leaving $request set would keep the full
 * hash index and every re-tokenized file's tokens/source reachable via
 * `$blockFinder->request` for the rest of the process — silently defeating
 * the caller's own `unset()` of its equivalents right after find() returns.
 * Measured impact of getting this wrong: ~20 MB retained per run that
 * should have been freed immediately.
 */
final class DuplicateBlockFinder
{
    private DuplicateSearchRequest $request;
    private ContentHintExtractor $hintExtractor;

    private DuplicateMatchCandidates $candidates;

    /**
     * @return list<DuplicateBlock>
     */
    public function find(DuplicateSearchRequest $request): array
    {
        $this->request = $request;
        $this->hintExtractor = new ContentHintExtractor();

        try {
            $this->candidates = new DuplicateMatchCandidates();

            foreach ($request->hashIndex as $positions) {
                $this->evaluateBucket($positions);
            }

            return array_map(
                fn(array $match): DuplicateBlock => $this->buildBlock(...$match),
                $this->candidates->withoutSubsumed($this->span(...)),
            );
        } finally {
            // Release scratch state — see class docblock for why this
            // matters. Must run even if evaluateBucket() throws: otherwise
            // the full hash index and every re-tokenized file's tokens
            // stay reachable via this long-lived instance for the rest of
            // the process (see the "Measured impact" note in the class
            // docblock).
            unset($this->request, $this->hintExtractor, $this->candidates);
        }
    }

    /**
     * @param list<int> $positions
     */
    private function evaluateBucket(array $positions): void
    {
        foreach ($this->groupByWindow($positions) as $group) {
            if (\count($group) >= 2 && !$this->continuesAnEarlierMatch($group)) {
                $this->extendGroup($group);
            }
        }
    }

    /**
     * Splits a bucket into groups whose first window is token-for-token
     * identical — equal hashes may still collide.
     *
     * @param list<int> $positions
     *
     * @return list<list<int>>
     */
    private function groupByWindow(array $positions): array
    {
        $groups = [];

        foreach (array_values(array_unique($positions)) as $packed) {
            if (!isset($this->request->retokenized->tokens[PackedPosition::fileIndex($packed)])) {
                continue;
            }

            foreach ($groups as $index => $group) {
                if ($this->windowsMatch($group[0], $packed)) {
                    $groups[$index][] = $packed;

                    continue 2;
                }
            }

            $groups[] = [$packed];
        }

        return array_values($groups);
    }

    private function windowsMatch(int $packedA, int $packedB): bool
    {
        $tokensA = $this->tokensAt($packedA);
        $tokensB = $this->tokensAt($packedB);
        $offsetA = PackedPosition::offset($packedA);
        $offsetB = PackedPosition::offset($packedB);

        for ($i = 0; $i < $this->request->minTokens; $i++) {
            if ($tokensA[$offsetA + $i]->value !== $tokensB[$offsetB + $i]->value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<int> $group
     */
    private function continuesAnEarlierMatch(array $group): bool
    {
        $precedingValues = [];

        foreach ($group as $packed) {
            $offset = PackedPosition::offset($packed);
            if ($offset <= 0) {
                return false;
            }

            $precedingValues[$this->tokensAt($packed)[$offset - 1]->value] = true;
        }

        return \count($precedingValues) === 1;
    }

    /**
     * Extends a group token by token while all members agree. Where they
     * stop agreeing, the group so far becomes a block and every subgroup of
     * two or more members that still agrees continues on its own, so a
     * copy that diverges early never shortens the match of the others.
     *
     * @param list<int> $group members that agree on the first minTokens tokens
     */
    private function extendGroup(array $group): void
    {
        $pending = [[$group, $this->request->minTokens]];

        while ($pending !== []) {
            [$members, $length] = array_pop($pending);
            $memberCount = \count($members);

            while (true) {
                $next = $this->groupByTokenAt($members, $length);
                if (\count($next) !== 1 || \count($next[0]) !== $memberCount) {
                    break;
                }
                $length++;
            }

            $copies = $this->reportableCopies($members, $length);
            if ($copies !== null) {
                $this->candidates->add($length, $copies);
            }

            foreach ($next as $subgroup) {
                if (\count($subgroup) >= 2) {
                    $pending[] = [$subgroup, $length + 1];
                }
            }
        }
    }

    /**
     * Groups members by the token at `offset + $length`; a member whose
     * stream ends there belongs to no group.
     *
     * @param list<int> $members
     *
     * @return list<list<int>>
     */
    private function groupByTokenAt(array $members, int $length): array
    {
        $groups = [];

        foreach ($members as $packed) {
            $token = $this->tokensAt($packed)[PackedPosition::offset($packed) + $length] ?? null;
            if ($token !== null) {
                $groups[$token->value][] = $packed;
            }
        }

        return array_values($groups);
    }

    /**
     * The copies of a match of `$length` tokens, or `null` when the match is
     * no block: a data table at every copy, fewer than two distinct copies,
     * or no copy reaching `minLines`. The longest copy admits every other,
     * a copy shorter than `minLines` included.
     *
     * @param list<int> $members
     *
     * @return ?list<int>
     */
    private function reportableCopies(array $members, int $length): ?array
    {
        if ($this->isSuppressedAsData($members, $length)) {
            return null;
        }

        $copies = $this->distinctCopies($members, $length);
        if (\count($copies) < 2) {
            return null;
        }

        $longest = max(array_map(function (int $copy) use ($length): int {
            [, $start, $end] = $this->span($copy, $length);

            return $end - $start + 1;
        }, $copies));

        return $longest < $this->request->minLines ? null : $copies;
    }

    /**
     * @param list<int> $copies
     */
    private function buildBlock(int $length, array $copies): DuplicateBlock
    {
        $locations = array_map(
            function (int $copy) use ($length): DuplicateLocation {
                [$file, $start, $end] = $this->span($copy, $length);

                return new DuplicateLocation(RelativePath::fromString($file), $start, $end);
            },
            $copies,
        );

        $lines = 0;
        foreach ($locations as $location) {
            $lines = max($lines, $location->lineCount());
        }

        $first = $copies[0];
        $source = $this->request->retokenized->sources[PackedPosition::fileIndex($first)] ?? null;

        return new DuplicateBlock(
            locations: $locations,
            lines: $lines,
            tokens: $length,
            contentHash: $this->contentHash($this->tokensAt($first), PackedPosition::offset($first), $length),
            hint: $source !== null ? $this->hintExtractor->extract($source, $locations[0]->startLine, $locations[0]->endLine) : null,
        );
    }

    /**
     * Same-file occurrences that share a line are one repetitive structure
     * matching itself at a shifted offset, not two copies: only the first
     * of them is kept. Occurrences that merely touch — one ends on the line
     * before the other starts — are two copies and both stay.
     *
     * Members arrive in bucket order: by file index, then by offset.
     *
     * @param list<int> $members
     *
     * @return list<int>
     */
    private function distinctCopies(array $members, int $length): array
    {
        $copies = [];
        /** @var array<int, int> $lastEndLineByFile */
        $lastEndLineByFile = [];

        foreach ($members as $packed) {
            $fileIdx = PackedPosition::fileIndex($packed);

            [, $start, $end] = $this->span($packed, $length);

            if (isset($lastEndLineByFile[$fileIdx]) && $start <= $lastEndLineByFile[$fileIdx]) {
                continue;
            }

            $lastEndLineByFile[$fileIdx] = $end;
            $copies[] = $packed;
        }

        return $copies;
    }

    /**
     * The file a copy of `$length` tokens is in, and its first and last line.
     *
     * @return array{string, int, int}
     */
    private function span(int $packed, int $length): array
    {
        $tokens = $this->tokensAt($packed);
        $offset = PackedPosition::offset($packed);

        return [
            $this->request->filePaths[PackedPosition::fileIndex($packed)],
            $tokens[$offset]->line,
            $tokens[$offset + $length - 1]->line,
        ];
    }

    /**
     * @return list<NormalizedToken>
     */
    private function tokensAt(int $packed): array
    {
        return $this->request->retokenized->tokens[PackedPosition::fileIndex($packed)];
    }

    /**
     * Creates the semantic identity for one fully verified duplicate block.
     *
     * The sequence is length-prefixed through JSON and carries its token count
     * explicitly, so neither source locations nor a shortened display hint can
     * influence the group identity.
     *
     * @param list<NormalizedToken> $tokens
     */
    private function contentHash(array $tokens, int $offset, int $length): string
    {
        $values = [];
        for ($i = 0; $i < $length; $i++) {
            $values[] = $tokens[$offset + $i]->value;
        }

        return hash('sha256', json_encode(
            ['tokenCount' => $length, 'tokens' => $values],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * Data-table suppression: a match entirely contained in a const/property
     * array declaration (see {@see DataDeclarationTagger}) at every
     * occurrence is the normal shape of that table, not duplication needing
     * extraction. A match that is data at one occurrence but executable code
     * at another is still a real duplication signal. This suppression is
     * unconditional — there is no option to disable it.
     *
     * @param list<int> $members
     */
    private function isSuppressedAsData(array $members, int $length): bool
    {
        foreach ($members as $packed) {
            $tokens = $this->tokensAt($packed);
            $offset = PackedPosition::offset($packed);

            for ($i = 0; $i < $length; $i++) {
                if (!$tokens[$offset + $i]->isData) {
                    return false;
                }
            }
        }

        return true;
    }
}
