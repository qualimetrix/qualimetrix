<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Duplication;

use AiMessDetector\Core\Duplication\DuplicateBlock;
use AiMessDetector\Core\Duplication\DuplicateLocation;
use SplFileInfo;

/**
 * Detects code duplication across PHP files using token-stream hashing (Rabin-Karp).
 *
 * Algorithm (memory-optimized two-pass):
 * 1. Stream files one-by-one: tokenize, compute rolling hashes, discard tokens immediately
 * 2. Prune hash index — remove hashes with only one occurrence (typically ~75%)
 * 3. Re-tokenize only files that participate in hash matches
 * 4. Verify token matches, extend blocks, compute line ranges
 * 5. Filter out blocks shorter than minLines, deduplicate overlapping blocks
 *
 * This two-pass approach avoids holding all tokens + full hash index simultaneously,
 * reducing peak memory from O(total_tokens + total_positions) to
 * O(total_positions) during pass 1 and O(matching_tokens + matching_positions) during pass 2.
 */
final class DuplicationDetector
{
    private const HASH_BASE = 33;
    private const HASH_MOD = 1_000_000_007;

    private TokenNormalizer $normalizer;

    private int $minTokens;
    private int $minLines;

    public function __construct()
    {
        $this->normalizer = new TokenNormalizer();
    }

    /**
     * Detects duplicate code blocks across the given files.
     *
     * @param list<SplFileInfo> $files
     *
     * @return list<DuplicateBlock>
     */
    public function detect(array $files, int $minTokens = 70, int $minLines = 5): array
    {
        $this->minTokens = $minTokens;
        $this->minLines = $minLines;

        // Pass 1: Build hash index streaming (tokenize → hash → discard tokens)
        // Uses integer file indices for compact position storage
        /** @var list<string> $filePaths maps fileIdx → realPath */
        $filePaths = [];
        /** @var array<int, list<array{int, int}>> $hashIndex maps hash → list of [fileIdx, offset] */
        $hashIndex = [];

        foreach ($files as $file) {
            $path = $file->getRealPath();
            if ($path === false) {
                continue;
            }

            $source = @file_get_contents($path);
            if ($source === false) {
                continue;
            }

            $tokens = $this->normalizer->normalize($source);
            if (\count($tokens) < $this->minTokens) {
                continue;
            }

            $fileIdx = \count($filePaths);
            $filePaths[] = $path;

            // Compute rolling hashes and add to index, then discard tokens
            $this->addFileHashesToIndex($tokens, $fileIdx, $hashIndex);
            // $tokens freed here — not stored
        }

        if ($hashIndex === []) {
            return [];
        }

        // Prune unique hashes — typically removes ~75% of entries
        foreach ($hashIndex as $hash => $positions) {
            if (\count($positions) < 2) {
                unset($hashIndex[$hash]);
            }
        }

        if ($hashIndex === []) {
            return [];
        }

        // Determine which files need re-tokenization
        $neededFileIndices = [];
        foreach ($hashIndex as $positions) {
            foreach ($positions as [$fileIdx]) {
                $neededFileIndices[$fileIdx] = true;
            }
        }

        // Pass 2: Re-tokenize only files with matching hashes
        /** @var array<int, list<NormalizedToken>> $fileTokens fileIdx → tokens */
        $fileTokens = [];
        foreach ($neededFileIndices as $fileIdx => $_) {
            $source = @file_get_contents($filePaths[$fileIdx]);
            if ($source === false) {
                continue;
            }
            $fileTokens[$fileIdx] = $this->normalizer->normalize($source);
        }

        // Find and extend duplicate blocks
        $rawBlocks = $this->findDuplicateBlocks($hashIndex, $fileTokens, $filePaths);

        // Free large structures before dedup sort
        unset($hashIndex, $fileTokens);

        // Filter and deduplicate
        return $this->filterAndDeduplicate($rawBlocks);
    }

    /**
     * Computes rolling hashes for a single file's tokens and adds them to the index.
     *
     * @param list<NormalizedToken> $tokens
     * @param array<int, list<array{int, int}>> $index modified by reference
     */
    private function addFileHashesToIndex(array $tokens, int $fileIdx, array &$index): void
    {
        $tokenCount = \count($tokens);
        if ($tokenCount < $this->minTokens) {
            return;
        }

        // Compute initial hash for the first window
        $hash = 0;
        $highPow = 1;

        for ($i = 0; $i < $this->minTokens; $i++) {
            $hash = ($hash * self::HASH_BASE + $this->tokenHash($tokens[$i])) % self::HASH_MOD;
            if ($i < $this->minTokens - 1) {
                $highPow = ($highPow * self::HASH_BASE) % self::HASH_MOD;
            }
        }

        $index[$hash][] = [$fileIdx, 0];

        // Roll the hash forward
        for ($i = 1; $i <= $tokenCount - $this->minTokens; $i++) {
            $outToken = $this->tokenHash($tokens[$i - 1]);
            $inToken = $this->tokenHash($tokens[$i + $this->minTokens - 1]);

            $hash = (($hash - (($outToken * $highPow) % self::HASH_MOD) + self::HASH_MOD) * self::HASH_BASE + $inToken) % self::HASH_MOD;

            $index[$hash][] = [$fileIdx, $i];
        }
    }

    /**
     * Finds duplicate blocks by verifying hash matches and extending them.
     *
     * @param array<int, list<array{int, int}>> $hashIndex hash → list of [fileIdx, offset]
     * @param array<int, list<NormalizedToken>> $fileTokens fileIdx → tokens
     * @param list<string> $filePaths fileIdx → realPath
     *
     * @return list<DuplicateBlock>
     */
    private function findDuplicateBlocks(array $hashIndex, array $fileTokens, array $filePaths): array
    {
        $blocks = [];
        /** @var array<string, true> $seen Track processed pairs to avoid duplicates */
        $seen = [];

        foreach ($hashIndex as $positions) {
            // Compare all pairs in this hash bucket
            $count = \count($positions);
            for ($i = 0; $i < $count - 1; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    [$fileIdxA, $offsetA] = $positions[$i];
                    [$fileIdxB, $offsetB] = $positions[$j];

                    // Skip same-file same-offset (trivial self-match)
                    if ($fileIdxA === $fileIdxB && $offsetA === $offsetB) {
                        continue;
                    }

                    // Canonical pair key using compact integer indices
                    $pairKey = $this->pairKey($fileIdxA, $offsetA, $fileIdxB, $offsetB);
                    if (isset($seen[$pairKey])) {
                        continue;
                    }
                    $seen[$pairKey] = true;

                    // Verify the tokens actually match (hash collision protection)
                    if (!isset($fileTokens[$fileIdxA], $fileTokens[$fileIdxB])) {
                        continue;
                    }

                    $tokensA = $fileTokens[$fileIdxA];
                    $tokensB = $fileTokens[$fileIdxB];

                    if (!$this->tokensMatch($tokensA, $offsetA, $tokensB, $offsetB, $this->minTokens)) {
                        continue;
                    }

                    // Extend the match forward
                    $matchLength = $this->extendMatch($tokensA, $offsetA, $tokensB, $offsetB);

                    // Compute line range
                    $startLineA = $tokensA[$offsetA]->line;
                    $endLineA = $tokensA[$offsetA + $matchLength - 1]->line;
                    $startLineB = $tokensB[$offsetB]->line;
                    $endLineB = $tokensB[$offsetB + $matchLength - 1]->line;

                    $lineCount = max($endLineA - $startLineA + 1, $endLineB - $startLineB + 1);

                    if ($lineCount < $this->minLines) {
                        continue;
                    }

                    $blocks[] = new DuplicateBlock(
                        locations: [
                            new DuplicateLocation($filePaths[$fileIdxA], $startLineA, $endLineA),
                            new DuplicateLocation($filePaths[$fileIdxB], $startLineB, $endLineB),
                        ],
                        lines: $lineCount,
                        tokens: $matchLength,
                    );
                }
            }
        }

        return $blocks;
    }

    /**
     * Extends a match forward past the initial window.
     *
     * @param list<NormalizedToken> $tokensA
     * @param list<NormalizedToken> $tokensB
     */
    private function extendMatch(array $tokensA, int $offsetA, array $tokensB, int $offsetB): int
    {
        $maxLen = min(\count($tokensA) - $offsetA, \count($tokensB) - $offsetB);
        $length = $this->minTokens;

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
     * Filters out blocks below thresholds and removes nested/overlapping blocks.
     *
     * @param list<DuplicateBlock> $blocks
     *
     * @return list<DuplicateBlock>
     */
    private function filterAndDeduplicate(array $blocks): array
    {
        if ($blocks === []) {
            return [];
        }

        // Sort by token count descending (prefer larger blocks)
        usort($blocks, static fn(DuplicateBlock $a, DuplicateBlock $b) => $b->tokens <=> $a->tokens);

        /** @var array<string, list<array{start: int, end: int}>> $covered file => covered ranges */
        $covered = [];
        $result = [];

        foreach ($blocks as $block) {
            $isSubsumed = true;

            foreach ($block->locations as $loc) {
                if (!$this->isRangeCovered($covered[$loc->file] ?? [], $loc->startLine, $loc->endLine)) {
                    $isSubsumed = false;

                    break;
                }
            }

            if ($isSubsumed) {
                continue;
            }

            $result[] = $block;

            foreach ($block->locations as $loc) {
                $covered[$loc->file][] = ['start' => $loc->startLine, 'end' => $loc->endLine];
            }
        }

        return $result;
    }

    /**
     * Checks if a line range is fully covered by existing ranges.
     *
     * @param list<array{start: int, end: int}> $ranges
     */
    private function isRangeCovered(array $ranges, int $start, int $end): bool
    {
        foreach ($ranges as $range) {
            if ($range['start'] <= $start && $range['end'] >= $end) {
                return true;
            }
        }

        return false;
    }

    private function tokenHash(NormalizedToken $token): int
    {
        // Use a simple hash of the token value
        $hash = 0;
        $value = $token->value;
        $len = min(\strlen($value), 16);

        for ($i = 0; $i < $len; $i++) {
            $hash = ($hash * 31 + \ord($value[$i])) % self::HASH_MOD;
        }

        return $hash;
    }

    private function pairKey(int $fileIdxA, int $offsetA, int $fileIdxB, int $offsetB): string
    {
        // Canonical order for the pair
        if ($fileIdxA > $fileIdxB || ($fileIdxA === $fileIdxB && $offsetA > $offsetB)) {
            return "{$fileIdxB}:{$offsetB}-{$fileIdxA}:{$offsetA}";
        }

        return "{$fileIdxA}:{$offsetA}-{$fileIdxB}:{$offsetB}";
    }
}
