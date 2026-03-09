<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Duplication;

use AiMessDetector\Core\Duplication\DuplicateBlock;
use AiMessDetector\Core\Duplication\DuplicateLocation;
use SplFileInfo;

/**
 * Detects code duplication across PHP files using token-stream hashing (Rabin-Karp).
 *
 * Algorithm:
 * 1. Tokenize and normalize each file (strip whitespace/comments, replace identifiers)
 * 2. Compute rolling hashes for sliding windows of minTokens size
 * 3. Group matching hashes into candidate duplicate pairs
 * 4. Extend matches forward to find the maximum duplicated block
 * 5. Filter out blocks shorter than minLines
 * 6. Deduplicate overlapping/nested blocks
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

        // Phase 1: Tokenize and normalize all files
        $fileTokens = $this->tokenizeFiles($files);

        if ($fileTokens === []) {
            return [];
        }

        // Phase 2: Build hash index (hash => list of positions across all files)
        $hashIndex = $this->buildHashIndex($fileTokens);

        // Phase 3: Find and extend duplicate blocks
        $rawBlocks = $this->findDuplicateBlocks($hashIndex, $fileTokens);

        // Phase 4: Filter and deduplicate
        return $this->filterAndDeduplicate($rawBlocks);
    }

    /**
     * @param list<SplFileInfo> $files
     *
     * @return array<string, list<NormalizedToken>> filepath => tokens
     */
    private function tokenizeFiles(array $files): array
    {
        $result = [];

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

            if (\count($tokens) >= $this->minTokens) {
                $result[$path] = $tokens;
            }
        }

        return $result;
    }

    /**
     * Builds a hash index using Rabin-Karp rolling hash.
     *
     * @param array<string, list<NormalizedToken>> $fileTokens
     *
     * @return array<int, list<array{file: string, offset: int}>>
     */
    private function buildHashIndex(array $fileTokens): array
    {
        $index = [];

        foreach ($fileTokens as $file => $tokens) {
            $tokenCount = \count($tokens);
            if ($tokenCount < $this->minTokens) {
                continue;
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

            $index[$hash][] = ['file' => $file, 'offset' => 0];

            // Roll the hash forward
            for ($i = 1; $i <= $tokenCount - $this->minTokens; $i++) {
                $outToken = $this->tokenHash($tokens[$i - 1]);
                $inToken = $this->tokenHash($tokens[$i + $this->minTokens - 1]);

                $hash = (($hash - (($outToken * $highPow) % self::HASH_MOD) + self::HASH_MOD) * self::HASH_BASE + $inToken) % self::HASH_MOD;

                $index[$hash][] = ['file' => $file, 'offset' => $i];
            }
        }

        return $index;
    }

    /**
     * Finds duplicate blocks by verifying hash matches and extending them.
     *
     * @param array<int, list<array{file: string, offset: int}>> $hashIndex
     * @param array<string, list<NormalizedToken>> $fileTokens
     *
     * @return list<DuplicateBlock>
     */
    private function findDuplicateBlocks(array $hashIndex, array $fileTokens): array
    {
        $blocks = [];
        /** @var array<string, true> $seen Track processed pairs to avoid duplicates */
        $seen = [];

        foreach ($hashIndex as $positions) {
            if (\count($positions) < 2) {
                continue;
            }

            // Compare all pairs in this hash bucket
            $count = \count($positions);
            for ($i = 0; $i < $count - 1; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = $positions[$i];
                    $b = $positions[$j];

                    // Skip same-file same-offset (trivial self-match)
                    if ($a['file'] === $b['file'] && $a['offset'] === $b['offset']) {
                        continue;
                    }

                    // Create a canonical pair key to avoid processing the same pair twice
                    $pairKey = $this->pairKey($a['file'], $a['offset'], $b['file'], $b['offset']);
                    if (isset($seen[$pairKey])) {
                        continue;
                    }
                    $seen[$pairKey] = true;

                    // Verify the tokens actually match (hash collision protection)
                    $tokensA = $fileTokens[$a['file']];
                    $tokensB = $fileTokens[$b['file']];

                    if (!$this->tokensMatch($tokensA, $a['offset'], $tokensB, $b['offset'], $this->minTokens)) {
                        continue;
                    }

                    // Extend the match forward
                    $matchLength = $this->extendMatch($tokensA, $a['offset'], $tokensB, $b['offset']);

                    // Compute line range
                    $startLineA = $tokensA[$a['offset']]->line;
                    $endLineA = $tokensA[$a['offset'] + $matchLength - 1]->line;
                    $startLineB = $tokensB[$b['offset']]->line;
                    $endLineB = $tokensB[$b['offset'] + $matchLength - 1]->line;

                    $lineCount = max($endLineA - $startLineA + 1, $endLineB - $startLineB + 1);

                    if ($lineCount < $this->minLines) {
                        continue;
                    }

                    $blocks[] = new DuplicateBlock(
                        locations: [
                            new DuplicateLocation($a['file'], $startLineA, $endLineA),
                            new DuplicateLocation($b['file'], $startLineB, $endLineB),
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

    private function pairKey(string $fileA, int $offsetA, string $fileB, int $offsetB): string
    {
        // Canonical order for the pair
        if ($fileA > $fileB || ($fileA === $fileB && $offsetA > $offsetB)) {
            return "{$fileB}:{$offsetB}-{$fileA}:{$offsetA}";
        }

        return "{$fileA}:{$offsetA}-{$fileB}:{$offsetB}";
    }
}
