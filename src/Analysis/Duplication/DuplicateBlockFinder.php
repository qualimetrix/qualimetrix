<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Duplication;

use Qualimetrix\Core\Duplication\DuplicateBlock;
use Qualimetrix\Core\Duplication\DuplicateLocation;
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
 * {@see find()} holds the current request/scratch state as instance
 * properties for the duration of one call so the nested-loop helpers below
 * don't have to thread six unchanging values through every signature — the
 * same pattern {@see DuplicationDetector} itself uses for its rule options.
 * This class is not reentrant; a single find() call must complete before
 * another begins (true for all current callers).
 *
 * find() clears these properties before returning. This instance is a
 * long-lived field of {@see DuplicationDetector} (constructed once, reused
 * across every detect() call), so leaving $request set would keep the full
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

    /** @var array<string, true> */
    private array $seen;

    /**
     * @return list<DuplicateBlock>
     */
    public function find(DuplicateSearchRequest $request): array
    {
        $this->request = $request;
        $this->hintExtractor = new ContentHintExtractor();
        $this->seen = [];

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
            $this->seen = [];
        }
    }

    /**
     * Compares every pair of positions sharing one hash bucket.
     *
     * @param list<int> $positions
     *
     * @return list<DuplicateBlock>
     */
    private function evaluateBucket(array $positions): array
    {
        $blocks = [];
        $count = \count($positions);

        for ($i = 0; $i < $count - 1; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $block = $this->evaluatePair($positions[$i], $positions[$j]);

                if ($block !== null) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    /**
     * Verifies a single candidate pair and, if it survives every filter,
     * builds the resulting {@see DuplicateBlock}.
     *
     * Works with plain ints rather than a pair value object: this method
     * runs once per candidate pair in every hash bucket — up to millions of
     * times for a large codebase — so keeping it allocation-light here
     * matters more than it does in the rest of this class.
     */
    private function evaluatePair(int $packedA, int $packedB): ?DuplicateBlock
    {
        $fileIdxA = PackedPosition::fileIndex($packedA);
        $offsetA = PackedPosition::offset($packedA);
        $fileIdxB = PackedPosition::fileIndex($packedB);
        $offsetB = PackedPosition::offset($packedB);

        // Skip same-file same-offset (trivial self-match)
        if ($this->isTrivialSelfMatch($fileIdxA, $offsetA, $fileIdxB, $offsetB)) {
            return null;
        }

        // Skip pairs already evaluated via another hash bucket
        $pairKey = self::pairKey($fileIdxA, $offsetA, $fileIdxB, $offsetB);
        if (isset($this->seen[$pairKey])) {
            return null;
        }
        $this->seen[$pairKey] = true;

        // Verify the tokens actually match (hash collision protection)
        $fileTokens = $this->request->retokenized->tokens;
        if (!isset($fileTokens[$fileIdxA], $fileTokens[$fileIdxB])) {
            return null;
        }

        $tokensA = $fileTokens[$fileIdxA];
        $tokensB = $fileTokens[$fileIdxB];

        if (!$this->tokensMatch($tokensA, $offsetA, $tokensB, $offsetB, $this->request->minTokens)) {
            return null;
        }

        // Extend the match forward
        $matchLength = $this->extendMatch($tokensA, $offsetA, $tokensB, $offsetB, $this->request->minTokens);

        if ($this->isSuppressedAsData($tokensA, $offsetA, $tokensB, $offsetB, $matchLength)) {
            return null;
        }

        $startLineA = $tokensA[$offsetA]->line;
        $endLineA = $tokensA[$offsetA + $matchLength - 1]->line;
        $startLineB = $tokensB[$offsetB]->line;
        $endLineB = $tokensB[$offsetB + $matchLength - 1]->line;

        if ($this->isSelfDuplicateOverlap($fileIdxA, $fileIdxB, $startLineA, $endLineA, $startLineB, $endLineB)) {
            return null;
        }

        $lineCount = max($endLineA - $startLineA + 1, $endLineB - $startLineB + 1);

        if ($lineCount < $this->request->minLines) {
            return null;
        }

        $locationA = new DuplicateLocation(RelativePath::fromString($this->request->filePaths[$fileIdxA]), $startLineA, $endLineA);
        $locationB = new DuplicateLocation(RelativePath::fromString($this->request->filePaths[$fileIdxB]), $startLineB, $endLineB);

        return $this->assembleBlock($fileIdxA, $locationA, $locationB, $lineCount, $matchLength);
    }

    private function isTrivialSelfMatch(int $fileIdxA, int $offsetA, int $fileIdxB, int $offsetB): bool
    {
        return $fileIdxA === $fileIdxB && $offsetA === $offsetB;
    }

    /**
     * @param int $fileIdxA index of the primary location, used to look up
     *                      its source for the content hint
     */
    private function assembleBlock(
        int $fileIdxA,
        DuplicateLocation $locationA,
        DuplicateLocation $locationB,
        int $lineCount,
        int $matchLength,
    ): DuplicateBlock {
        $sourceA = $this->request->retokenized->sources[$fileIdxA] ?? null;
        $hint = $sourceA !== null
            ? $this->hintExtractor->extract($sourceA, $locationA->startLine, $locationA->endLine)
            : null;

        return new DuplicateBlock(
            locations: [$locationA, $locationB],
            lines: $lineCount,
            tokens: $matchLength,
            hint: $hint,
        );
    }

    /**
     * Data-table suppression: a match entirely contained in a const/property
     * array declaration (see {@see DataDeclarationTagger}) on both sides is
     * the normal shape of that table, not duplication needing extraction.
     * A match that is data on one side but executable code on the other is
     * still a real duplication signal, hence checking both sides.
     *
     * @param list<NormalizedToken> $tokensA
     * @param list<NormalizedToken> $tokensB
     */
    private function isSuppressedAsData(array $tokensA, int $offsetA, array $tokensB, int $offsetB, int $matchLength): bool
    {
        if ($this->request->includeConstantArrays) {
            return false;
        }

        return $this->isEntirelyData($tokensA, $offsetA, $matchLength)
            && $this->isEntirelyData($tokensB, $offsetB, $matchLength);
    }

    /**
     * Checks whether every token in the given range is flagged as data by
     * {@see DataDeclarationTagger}.
     *
     * @param list<NormalizedToken> $tokens
     */
    private function isEntirelyData(array $tokens, int $offset, int $length): bool
    {
        for ($i = 0; $i < $length; $i++) {
            if (!$tokens[$offset + $i]->isData) {
                return false;
            }
        }

        return true;
    }

    /**
     * Skip self-duplication: same file, overlapping or adjacent line ranges.
     * Repetitive structures (large constant arrays) produce many matching
     * token windows at different offsets that map to overlapping or
     * touching line ranges.
     *
     * totalSize >= unionSpan means ranges overlap or touch (gap <= 0). This
     * catches self-duplication from repetitive data structures where
     * matching token windows in different parts of the same structure
     * produce overlapping or immediately adjacent line ranges. Truly
     * separated blocks (like two identical functions with a blank line gap
     * between them) have totalSize < unionSpan and are not filtered.
     */
    private function isSelfDuplicateOverlap(int $fileIdxA, int $fileIdxB, int $startLineA, int $endLineA, int $startLineB, int $endLineB): bool
    {
        if ($fileIdxA !== $fileIdxB) {
            return false;
        }

        $totalSize = ($endLineA - $startLineA + 1) + ($endLineB - $startLineB + 1);
        $unionSpan = max($endLineA, $endLineB) - min($startLineA, $startLineB) + 1;

        return $totalSize >= $unionSpan;
    }

    /**
     * Extends a match forward past the initial (already-verified) window.
     *
     * @param list<NormalizedToken> $tokensA
     * @param list<NormalizedToken> $tokensB
     */
    private function extendMatch(array $tokensA, int $offsetA, array $tokensB, int $offsetB, int $minTokens): int
    {
        $maxLen = min(\count($tokensA) - $offsetA, \count($tokensB) - $offsetB);
        $length = $minTokens;

        while ($length < $maxLen) {
            if ($tokensA[$offsetA + $length]->value !== $tokensB[$offsetB + $length]->value) {
                break;
            }
            $length++;
        }

        return $length;
    }

    /**
     * Verifies that tokens at the given positions actually match.
     *
     * @param list<NormalizedToken> $tokensA
     * @param list<NormalizedToken> $tokensB
     */
    private function tokensMatch(array $tokensA, int $offsetA, array $tokensB, int $offsetB, int $length): bool
    {
        for ($i = 0; $i < $length; $i++) {
            if ($tokensA[$offsetA + $i]->value !== $tokensB[$offsetB + $i]->value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Canonical (order-independent) key identifying a pair of positions,
     * used to avoid evaluating the same pair twice.
     */
    private static function pairKey(int $fileIdxA, int $offsetA, int $fileIdxB, int $offsetB): string
    {
        if ($fileIdxA > $fileIdxB || ($fileIdxA === $fileIdxB && $offsetA > $offsetB)) {
            return "{$fileIdxB}:{$offsetB}-{$fileIdxA}:{$offsetA}";
        }

        return "{$fileIdxA}:{$offsetA}-{$fileIdxB}:{$offsetB}";
    }
}
