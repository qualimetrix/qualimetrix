<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Duplication;

use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Duplication\DuplicateBlock;
use SplFileInfo;

/**
 * Detects code duplication across PHP files using token-stream hashing (Rabin-Karp).
 *
 * Algorithm (memory-optimized two-pass), one phase per collaborator:
 * 1. {@see HashIndexBuilder} streams files one-by-one: tokenize, compute
 *    rolling hashes, discard tokens immediately, then prune hashes with a
 *    single occurrence (typically ~75%)
 * 2. {@see retokenizeNeeded()} re-tokenizes only the files that participate
 *    in a hash match
 * 3. {@see DuplicateBlockFinder} verifies token matches, extends blocks,
 *    computes line ranges, and applies the data-table / self-duplication
 *    filters
 * 4. {@see filterAndDeduplicate()} drops blocks shorter than minLines and
 *    removes nested/overlapping blocks
 *
 * Memory optimizations:
 * - Two-pass avoids holding all tokens + full hash index simultaneously
 * - Positions packed as single int (see {@see PackedPosition}) instead of 2-element arrays
 * - Hash index pruned before re-tokenization pass
 * - Only files with matches are re-tokenized
 *
 * Data-table suppression: matches entirely contained within a `const`
 * declaration or a property's array-literal initializer are skipped by
 * default (see {@see DataDeclarationTagger}) — repeated key/value shape
 * across the rows of a constant lookup table is the normal form of that
 * table, not code duplication needing extraction. Set the
 * `include_constant_arrays` rule option to restore the previous behavior.
 */
final class DuplicationDetector implements DuplicationDetectorInterface
{
    private HashIndexBuilder $hashIndexBuilder;
    private TokenNormalizer $normalizer;
    private DuplicateBlockFinder $blockFinder;

    private int $minTokens;
    private int $minLines;
    private bool $includeConstantArrays;

    public function __construct(
        private readonly ConfigurationProviderInterface $configurationProvider,
    ) {
        $this->hashIndexBuilder = new HashIndexBuilder();
        $this->normalizer = new TokenNormalizer();
        $this->blockFinder = new DuplicateBlockFinder();
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
        $this->loadOptions();

        $projectRoot = $this->configurationProvider->getConfiguration()->projectRoot;

        $indexResult = $this->hashIndexBuilder->build($files, $projectRoot, $this->minTokens);
        if ($indexResult->isEmpty()) {
            return [];
        }

        $retokenized = $this->retokenizeNeeded($indexResult->ioPaths, $indexResult->neededFileIndices());

        $rawBlocks = $this->blockFinder->find(new DuplicateSearchRequest(
            hashIndex: $indexResult->hashIndex,
            retokenized: $retokenized,
            filePaths: $indexResult->filePaths,
            minTokens: $this->minTokens,
            minLines: $this->minLines,
            includeConstantArrays: $this->includeConstantArrays,
        ));

        // Free large structures before dedup sort
        unset($indexResult, $retokenized);

        return $this->filterAndDeduplicate($rawBlocks);
    }

    private function loadOptions(): void
    {
        $ruleOptions = $this->configurationProvider->getRuleOptions();
        $dupOptions = $ruleOptions['duplication.code-duplication'] ?? [];
        $this->minTokens = (int) ($dupOptions['min_tokens'] ?? $dupOptions['minTokens'] ?? 70);
        $this->minLines = (int) ($dupOptions['min_lines'] ?? $dupOptions['minLines'] ?? 5);
        $this->includeConstantArrays = (bool) ($dupOptions['include_constant_arrays'] ?? $dupOptions['includeConstantArrays'] ?? false);
    }

    /**
     * Pass 2: re-tokenizes only the files that participate in a hash match.
     *
     * @param list<string> $ioPaths fileIdx → path as supplied by the file source
     * @param array<int, true> $neededFileIndices fileIdx → true
     */
    private function retokenizeNeeded(array $ioPaths, array $neededFileIndices): RetokenizedFiles
    {
        /** @var array<int, list<NormalizedToken>> $fileTokens fileIdx → tokens */
        $fileTokens = [];
        /** @var array<int, string> $fileSources fileIdx → source content (for hint extraction) */
        $fileSources = [];

        foreach ($neededFileIndices as $fileIdx => $_) {
            $source = @file_get_contents($ioPaths[$fileIdx]);
            if ($source === false) {
                continue;
            }
            $fileTokens[$fileIdx] = $this->normalizer->normalize($source);
            $fileSources[$fileIdx] = $source;
        }

        return new RetokenizedFiles($fileTokens, $fileSources);
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
                if (!$this->isRangeCovered($covered[$loc->pathString()] ?? [], $loc->startLine, $loc->endLine)) {
                    $isSubsumed = false;

                    break;
                }
            }

            if ($isSubsumed) {
                continue;
            }

            $result[] = $block;

            foreach ($block->locations as $loc) {
                $covered[$loc->pathString()][] = ['start' => $loc->startLine, 'end' => $loc->endLine];
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
}
