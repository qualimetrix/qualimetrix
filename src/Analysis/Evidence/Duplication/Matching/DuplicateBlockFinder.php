<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Matching;

use Qualimetrix\Analysis\Evidence\Duplication\DuplicationDetector;
use Qualimetrix\Analysis\Evidence\Duplication\Index\HashIndexBuildResult;
use Qualimetrix\Analysis\Evidence\Duplication\Index\PackedPosition;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\DataDeclarationTagger;
use Qualimetrix\Analysis\Evidence\Duplication\Token\TokenStream;
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
    private DataRuns $dataRuns;
    private int $work = 0;

    /**
     * @return list<DuplicateBlock>
     */
    public function find(DuplicateSearchRequest $request): array
    {
        $this->request = $request;
        $this->hintExtractor = new ContentHintExtractor();
        $this->dataRuns = new DataRuns($request->retokenized->streams);
        $this->work = 0;

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

            $merged = (new DuplicateContentMerger($request->retokenized->streams, $reportable))->merge($segments);

            return array_map(
                fn(array $match): DuplicateBlock => $this->buildBlock(...$match),
                $merged->withoutSubsumed($this->coverSpan(...)),
            );
        } finally {
            // Exceptions must also release the dataset held by this long-lived instance.
            unset($this->request, $this->hintExtractor, $this->candidates, $this->dataRuns);
        }
    }

    /**
     * Token comparisons and member steps the last {@see find()} spent
     * extending groups: a deterministic measure of its matching cost.
     */
    public function extensionWork(): int
    {
        return $this->work;
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
     * Extends a group while all members agree. Where they stop agreeing, the
     * group so far becomes a block and every subgroup of two or more members
     * that still agrees continues on its own, so a copy that diverges early
     * never shortens the match of the others. Subgroups that leave the
     * anchor are explored before the anchor's own continuation: insertion
     * order breaks ties among candidates and so reaches the reported order.
     *
     * @param non-empty-list<int> $group members that agree on the first minTokens tokens
     */
    private function extendGroup(array $group): void
    {
        /** @var list<AnchorChain|array{non-empty-list<int>, int}> $pending */
        $pending = [[$group, $this->request->minTokens]];

        while ($pending !== []) {
            $entry = array_pop($pending);
            $chain = $entry instanceof AnchorChain ? $entry : $this->chainOf(...$entry);
            $length = $chain->length();

            if (!$chain->isData()) {
                $copies = $chain->distinctCopies();
                if ($this->reachesMinLines($copies, $length)) {
                    $this->candidates->add($length, $copies);
                }
            }

            [$sides, $continues] = $chain->split();
            if ($continues) {
                $pending[] = $chain;
            } else {
                $this->work += $chain->work();
            }
            // A finished chain must not outlive the construction of the next one.
            unset($chain, $entry);
            foreach ($sides as $side) {
                $pending[] = [$side, $length + 1];
            }
        }
    }

    /**
     * @param non-empty-list<int> $members
     */
    private function chainOf(array $members, int $known): AnchorChain
    {
        $streams = $this->request->retokenized->streams;
        [$lengths, $comparisons] = AnchoredPrefixLengths::of($streams, $members, $known);
        $this->work += $comparisons;

        return new AnchorChain($streams, $members, $lengths, $this->dataRuns);
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

        return $this->reachesMinLines($copies, $length) ? $copies : null;
    }

    /**
     * Two or more distinct copies, the longest reaching `minLines`; that copy
     * admits every other, a copy shorter than `minLines` included.
     *
     * @param list<int> $copies
     */
    private function reachesMinLines(array $copies, int $length): bool
    {
        if (\count($copies) < 2) {
            return false;
        }

        $longest = 0;
        foreach ($copies as $copy) {
            $longest = max($longest, $this->tokensAt($copy)->coveredLines(PackedPosition::offset($copy), $length));
        }

        return $longest >= $this->request->minLines;
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
            contentHash: DuplicateContentMerger::contentHash($this->tokensAt($first), PackedPosition::offset($first), $length),
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
            if ($this->dataRuns->endAt($packed) < PackedPosition::offset($packed) + $length) {
                return false;
            }
        }

        return true;
    }
}
