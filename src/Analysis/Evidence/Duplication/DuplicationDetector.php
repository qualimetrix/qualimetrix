<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication;

use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Evidence\Duplication\Index\HashIndexBuilder;
use Qualimetrix\Analysis\Evidence\Duplication\Index\PackedPosition;
use Qualimetrix\Analysis\Evidence\Duplication\Matching\DuplicateBlockFinder;
use Qualimetrix\Analysis\Evidence\Duplication\Matching\DuplicateSearchRequest;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\DataDeclarationTagger;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\RetokenizedFiles;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenNormalizer;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenStream;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Run\Contract\FileSetInspectionFailure;
use Qualimetrix\Analysis\Run\Contract\FileSetInspectionParticipantInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use SplFileInfo;

/**
 * Detects code duplication across PHP files using token-stream hashing (Rabin-Karp).
 *
 * Algorithm (memory-bounded candidate pre-pass plus exact verification):
 * 1. {@see HashIndexBuilder} streams files one-by-one into a fixed-size,
 *    saturating candidate filter, then makes a second full stream to retain
 *    all positions for its candidates. Collisions can add candidates but
 *    cannot remove a real repeated hash.
 * 2. {@see retokenizeNeeded()} re-tokenizes only the files that participate
 *    in a hash match
 * 3. {@see DuplicateBlockFinder} verifies token matches, extends every
 *    group of copies into one match, computes line ranges, applies the
 *    data-table / self-duplication / minLines filters, and drops a match
 *    only when all its copies lie in one connected component of retained covers
 *
 * Memory optimizations:
 * - Two-pass avoids holding all tokens + full hash index simultaneously
 * - Positions packed as single int (see {@see PackedPosition}) instead of 2-element arrays
 * - Hash index pruned before re-tokenization pass
 * - Only files with matches are re-tokenized
 *
 * Data-table suppression: matches entirely contained within a `const`
 * declaration or a property's array-literal initializer are skipped
 * unconditionally (see {@see DataDeclarationTagger}) — repeated key/value
 * shape across the rows of a constant lookup table is the normal form of
 * that table, not code duplication needing extraction.
 */
final class DuplicationDetector implements FileSetInspectionParticipantInterface
{
    private HashIndexBuilder $hashIndexBuilder;
    private TokenNormalizer $normalizer;
    private DuplicateBlockFinder $blockFinder;

    public function __construct(
        private readonly RuleConfigurationInterface $ruleConfiguration,
        private readonly DuplicationResultProvider $resultProvider,
        private readonly LoggerInterface $logger = new NullLogger(),
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
     */
    public function inspect(array $files, AbsolutePath $projectRoot): void
    {
        $this->resultProvider->reset();
        $this->detect($files, $projectRoot);
        $this->logger->info('Duplication detection completed');
    }

    public static function participantId(): string
    {
        return 'duplication';
    }

    public static function producerRuleName(): string
    {
        return 'duplication.clone';
    }

    public function resetForRun(): void
    {
        $this->resultProvider->reset();
    }

    /** @param list<SplFileInfo> $files */
    private function detect(array $files, AbsolutePath $projectRoot): void
    {
        $options = $this->ruleConfiguration->resolvedOptions()->for('duplication.clone');
        if (!$options instanceof CodeDuplicationOptions) {
            throw new LogicException('The duplication producer requires CodeDuplicationOptions.');
        }

        $indexResult = $this->hashIndexBuilder->build($files, $projectRoot, $options->min_tokens);
        if ($indexResult->isEmpty()) {
            $this->resultProvider->replace([]);

            return;
        }

        $retokenized = $this->retokenizeNeeded($indexResult->ioPaths, $indexResult->neededFileIndices(), $projectRoot);

        $blocks = $this->blockFinder->find(new DuplicateSearchRequest(
            hashIndex: $indexResult->hashIndex,
            retokenized: $retokenized,
            filePaths: $indexResult->filePaths,
            minTokens: $options->min_tokens,
            minLines: $options->min_lines,
        ));

        unset($indexResult, $retokenized);
        // Later finding phases need the allocator pages of the released dataset.
        gc_mem_caches();

        $this->resultProvider->replace($blocks);
    }

    /**
     * Pass 2: re-tokenizes only the files that participate in a hash match.
     *
     * @param list<string> $ioPaths fileIdx → path as supplied by the file source
     * @param array<int, true> $neededFileIndices fileIdx → true
     */
    private function retokenizeNeeded(array $ioPaths, array $neededFileIndices, AbsolutePath $projectRoot): RetokenizedFiles
    {
        /** @var array<int, TokenStream> $fileTokens fileIdx → tokens */
        $fileTokens = [];
        /** @var array<int, string> $fileSources fileIdx → source content (for hint extraction) */
        $fileSources = [];

        foreach ($neededFileIndices as $fileIdx => $_) {
            $ioPath = $ioPaths[$fileIdx] ?? throw new LogicException('Duplication retokenization referenced an unknown selected file index.');
            $source = @file_get_contents($ioPath);
            if ($source === false) {
                throw new FileSetInspectionFailure([[
                    'input' => PathFactory::fromCliArgument($ioPath, $projectRoot),
                    'message' => 'Cannot read selected file during duplication retokenization.',
                ]]);
            }
            $fileTokens[$fileIdx] = $this->normalizer->normalize($source);
            $fileSources[$fileIdx] = $source;
        }

        return new RetokenizedFiles($fileTokens, $fileSources);
    }
}
