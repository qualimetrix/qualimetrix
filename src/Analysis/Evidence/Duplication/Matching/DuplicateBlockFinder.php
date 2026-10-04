<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Matching;

use Qualimetrix\Analysis\Evidence\Duplication\DuplicationDetector;
use Qualimetrix\Analysis\Evidence\Duplication\Index\HashIndexBuildResult;
use Qualimetrix\Analysis\Evidence\Duplication\Index\PackedPosition;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\DataDeclarationTagger;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenStream;
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
 * and retained blocks for that exact sequence grow linearly with its copies:
 * comparing copies pairwise grows quadratically and, because every window
 * offset of a copied block is its own bucket, retains a block per pair per
 * offset — 99 copies of one 150-token class exhausted a 128M limit.
 *
 * A group whose members all share the preceding token is skipped: the
 * bucket of that preceding window holds the same members one token
 * earlier and yields a block that contains this one.
 *
 * Retained covers must connect all copies before a candidate is dropped.
 * Balanced segments are admitted separately, with the whole match retained
 * when none passes; a second coverage reduction precedes block allocation.
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

            $segments = new DuplicateMatchCandidates();
            $reportable = $this->reportableCopies(...);
            foreach ($this->candidates->withoutSubsumed($this->coverSpan(...)) as [$length, $copies]) {
                $first = $copies[0];
                BalancedSegments::addAdmittedTo(
                    $this->tokensAt($first),
                    PackedPosition::offset($first),
                    $length,
                    $copies,
                    $request->minTokens,
                    $segments,
                    $reportable,
                );
            }
            unset($this->candidates);

            return array_map(
                fn(array $match): DuplicateBlock => $this->buildBlock(...$match),
                $segments->withoutSubsumed($this->coverSpan(...)),
            );
        } finally {
            // Exceptions must also release the dataset held by this long-lived instance.
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

        foreach ($positions as $packed) {
            if (!isset($this->request->retokenized->streams[PackedPosition::fileIndex($packed)])) {
                continue;
            }

            // Holding a group value here would copy its growing array on every append.
            foreach (array_keys($groups) as $index) {
                if ($this->windowsMatch($groups[$index][0], $packed)) {
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
            if ($tokensA->values[$offsetA + $i] !== $tokensB->values[$offsetB + $i]) {
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

            $precedingValues[$this->tokensAt($packed)->values[$offset - 1]] = true;
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
            $tokens = $this->tokensAt($packed);
            $position = PackedPosition::offset($packed) + $length;
            if (isset($tokens->values[$position])) {
                $groups[$tokens->values[$position]][] = $packed;
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

        $longest = 0;
        foreach ($copies as $copy) {
            $longest = max($longest, $this->tokensAt($copy)->coveredLines(PackedPosition::offset($copy), $length));
        }

        return $longest < $this->request->minLines ? null : $copies;
    }

    /**
     * @param list<int> $copies
     */
    private function buildBlock(int $length, array $copies): DuplicateBlock
    {
        $locations = [];
        foreach ($copies as $copy) {
            $fileIndex = PackedPosition::fileIndex($copy);
            $offset = PackedPosition::offset($copy);
            $tokens = $this->tokensAt($copy);
            $last = $offset + $length - 1;
            $source = $this->request->retokenized->sources[$fileIndex] ?? null;
            $locations[] = new DuplicateLocation(
                file: RelativePath::fromString($this->request->filePaths[$fileIndex]),
                startLine: $tokens->startLine($offset),
                endLine: $tokens->endLine($last),
                codeLines: $tokens->coveredLines($offset, $length),
                hint: $source !== null ? $this->hintExtractor->extract($source, $tokens->startByte($offset), $tokens->endByte($last)) : null,
            );
        }

        $first = $copies[0];

        return new DuplicateBlock(
            locations: $locations,
            tokens: $length,
            contentHash: $this->contentHash($this->tokensAt($first), PackedPosition::offset($first), $length),
        );
    }

    /**
     * Members arrive in file/offset order. Half-open token intervals keep
     * adjacent copies even when both touch the same physical line.
     *
     * @param list<int> $members
     *
     * @return list<int>
     */
    private function distinctCopies(array $members, int $length): array
    {
        $lastEndByFile = [];
        $copies = [];
        foreach ($members as $packed) {
            $file = PackedPosition::fileIndex($packed);
            $offset = PackedPosition::offset($packed);
            if (isset($lastEndByFile[$file]) && $offset < $lastEndByFile[$file]) {
                continue;
            }
            $lastEndByFile[$file] = $offset + $length;
            $copies[] = $packed;
        }

        return $copies;
    }

    /** @return array{int, int, int} File and half-open token interval. */
    private function coverSpan(int $packed, int $length): array
    {
        $offset = PackedPosition::offset($packed);

        return [PackedPosition::fileIndex($packed), $offset, $offset + $length];
    }

    private function tokensAt(int $packed): TokenStream
    {
        return $this->request->retokenized->streams[PackedPosition::fileIndex($packed)];
    }

    /**
     * Creates the semantic identity for one fully verified duplicate block.
     *
     * The sequence is length-prefixed through JSON and carries its token count
     * explicitly, so neither source locations nor a shortened display hint can
     * influence the group identity.
     */
    private function contentHash(TokenStream $tokens, int $offset, int $length): string
    {
        $values = \array_slice($tokens->values, $offset, $length);

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

            if (strspn($tokens->dataMask, '1', $offset, $length) !== $length) {
                return false;
            }
        }

        return true;
    }
}
