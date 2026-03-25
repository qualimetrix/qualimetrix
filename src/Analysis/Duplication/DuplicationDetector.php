<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Duplication;

use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Duplication\DuplicateBlock;
use Qualimetrix\Core\Duplication\DuplicateLocation;
use Qualimetrix\Core\Util\PathNormalizer;
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
 * Memory optimizations:
 * - Two-pass avoids holding all tokens + full hash index simultaneously
 * - Positions packed as single int (fileIdx << 20 | offset) instead of 2-element arrays
 * - Hash index pruned before re-tokenization pass
 * - Only files with matches are re-tokenized
 */
final class DuplicationDetector
{
    private const HASH_BASE = 33;
    private const HASH_MOD = 1_000_000_007;

    /**
     * Bit shift for packing fileIdx and offset into a single int.
     * Supports up to 1,048,575 tokens per file (20 bits) and ~8.7M files.
     */
    private const OFFSET_BITS = 20;
    private const OFFSET_MASK = (1 << self::OFFSET_BITS) - 1; // 0xFFFFF

    private TokenNormalizer $normalizer;

    private int $minTokens;
    private int $minLines;

    public function __construct(
        private readonly ConfigurationProviderInterface $configurationProvider,
    ) {
        $this->normalizer = new TokenNormalizer();
    }

    /**
     * Detects duplicate code blocks across the given files.
     *
     * Reads min_tokens and min_lines thresholds from rule configuration.
     *
     * @param list<SplFileInfo> $files
     *
     * @return list<DuplicateBlock>
     */
    public function detect(array $files): array
    {
        $ruleOptions = $this->configurationProvider->getRuleOptions();
        $dupOptions = $ruleOptions['duplication.code-duplication'] ?? [];
        $this->minTokens = (int) ($dupOptions['min_tokens'] ?? $dupOptions['minTokens'] ?? 70);
        $this->minLines = (int) ($dupOptions['min_lines'] ?? $dupOptions['minLines'] ?? 5);

        // Pass 1: Build hash index streaming (tokenize → hash → discard tokens)
        // Positions are packed as (fileIdx << 20 | offset) to avoid array-per-position overhead
        /** @var list<string> $filePaths maps fileIdx → realPath */
        $filePaths = [];
        /** @var array<int, list<int>> $hashIndex maps hash → list of packed positions */
        $hashIndex = [];

        foreach ($files as $file) {
            $path = PathNormalizer::relativize($file->getPathname());

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
            foreach ($positions as $packed) {
                $neededFileIndices[$packed >> self::OFFSET_BITS] = true;
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
     * @param array<int, list<int>> $index modified by reference
     */
    private function addFileHashesToIndex(array $tokens, int $fileIdx, array &$index): void
    {
        $tokenCount = \count($tokens);
        if ($tokenCount < $this->minTokens) {
            return;
        }

        $packedBase = $fileIdx << self::OFFSET_BITS;

        // Compute initial hash for the first window
        $hash = 0;
        $highPow = 1;

        for ($i = 0; $i < $this->minTokens; $i++) {
            $hash = ($hash * self::HASH_BASE + $this->tokenHash($tokens[$i])) % self::HASH_MOD;
            if ($i < $this->minTokens - 1) {
                $highPow = ($highPow * self::HASH_BASE) % self::HASH_MOD;
            }
        }

        $index[$hash][] = $packedBase; // offset 0

        // Roll the hash forward
        for ($i = 1; $i <= $tokenCount - $this->minTokens; $i++) {
            $outToken = $this->tokenHash($tokens[$i - 1]);
            $inToken = $this->tokenHash($tokens[$i + $this->minTokens - 1]);

            $hash = (($hash - (($outToken * $highPow) % self::HASH_MOD) + self::HASH_MOD) * self::HASH_BASE + $inToken) % self::HASH_MOD;

            $index[$hash][] = $packedBase | $i;
        }
    }

    /**
     * Finds duplicate blocks by verifying hash matches and extending them.
     *
     * @param array<int, list<int>> $hashIndex hash → list of packed positions
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
                $fileIdxA = $positions[$i] >> self::OFFSET_BITS;
                $offsetA = $positions[$i] & self::OFFSET_MASK;

                for ($j = $i + 1; $j < $count; $j++) {
                    $fileIdxB = $positions[$j] >> self::OFFSET_BITS;
                    $offsetB = $positions[$j] & self::OFFSET_MASK;

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
