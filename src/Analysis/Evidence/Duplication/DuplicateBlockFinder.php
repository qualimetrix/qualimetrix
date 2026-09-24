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

    /**
     * @return list<DuplicateBlock>
     */
    public function find(DuplicateSearchRequest $request): array
    {
        $this->request = $request;
        $this->hintExtractor = new ContentHintExtractor();

        try {
            $blocks = [];

            foreach ($request->hashIndex as $positions) {
                foreach ($this->evaluateBucket($positions) as $block) {
                    $blocks[] = $block;
                }
            }

            return $blocks;
        } finally {
            // Release scratch state — see class docblock for why this
            // matters. Must run even if evaluateBucket() throws: otherwise
            // the full hash index and every re-tokenized file's tokens
            // stay reachable via this long-lived instance for the rest of
            // the process (see the "Measured impact" note in the class
            // docblock).
            unset($this->request, $this->hintExtractor);
        }
    }

    /**
     * @param list<int> $positions
     *
     * @return list<DuplicateBlock>
     */
    private function evaluateBucket(array $positions): array
    {
        $blocks = [];

        foreach ($this->groupByWindow($positions) as $group) {
            if (\count($group) < 2 || $this->continuesAnEarlierMatch($group)) {
                continue;
            }

            foreach ($this->extendGroup($group) as $block) {
                $blocks[] = $block;
            }
        }

        return $blocks;
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
     *
     * @return list<DuplicateBlock>
     */
    private function extendGroup(array $group): array
    {
        $blocks = [];
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

            $block = $this->buildBlock($members, $length);
            if ($block !== null) {
                $blocks[] = $block;
            }

            foreach ($next as $subgroup) {
                if (\count($subgroup) >= 2) {
                    $pending[] = [$subgroup, $length + 1];
                }
            }
        }

        return $blocks;
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
     * @param list<int> $members
     */
    private function buildBlock(array $members, int $length): ?DuplicateBlock
    {
        if ($this->isSuppressedAsData($members, $length)) {
            return null;
        }

        $locations = $this->distinctLocations($members, $length);
        if (\count($locations) < 2) {
            return null;
        }

        $lineCount = max(array_map(static fn(DuplicateLocation $location): int => $location->lineCount(), $locations));
        if ($lineCount < $this->request->minLines) {
            return null;
        }

        $first = $members[0];
        $source = $this->request->retokenized->sources[PackedPosition::fileIndex($first)] ?? null;

        return new DuplicateBlock(
            locations: $locations,
            lines: $lineCount,
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
     * @return list<DuplicateLocation>
     */
    private function distinctLocations(array $members, int $length): array
    {
        $locations = [];
        /** @var array<int, int> $lastEndLineByFile */
        $lastEndLineByFile = [];

        foreach ($members as $packed) {
            $fileIdx = PackedPosition::fileIndex($packed);
            $offset = PackedPosition::offset($packed);
            $tokens = $this->tokensAt($packed);
            $startLine = $tokens[$offset]->line;
            $endLine = $tokens[$offset + $length - 1]->line;

            if (isset($lastEndLineByFile[$fileIdx]) && $startLine <= $lastEndLineByFile[$fileIdx]) {
                continue;
            }

            $lastEndLineByFile[$fileIdx] = $endLine;
            $locations[] = new DuplicateLocation(RelativePath::fromString($this->request->filePaths[$fileIdx]), $startLine, $endLine);
        }

        return $locations;
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
