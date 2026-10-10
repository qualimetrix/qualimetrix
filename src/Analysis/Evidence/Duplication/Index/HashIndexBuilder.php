<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Index;

use Qualimetrix\Analysis\Evidence\Duplication\Matching\DuplicateBlockFinder;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenNormalizer;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenStream;
use Qualimetrix\Analysis\Run\Contract\FileSetInspectionFailure;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use SplFileInfo;

/**
 * Pass 1 of the Rabin-Karp duplication scan: stream each file once,
 * tokenize it, compute rolling hashes for every {@see minTokens}-wide
 * window, and record only their compact saturating candidate state — then
 * discard the tokens immediately (they are not retained past this method).
 *
 * A second full stream rebuilds an exact index only for the candidate hashes,
 * retaining their first and every subsequent position. Compact collisions can
 * add work to that stream, but cannot remove a true duplicate candidate.
 *
 * Kept separate from match verification ({@see DuplicateBlockFinder}) so the
 * first pass stays fixed-size rather than proportional to all token windows,
 * while pass two remains exact before the finder verifies token equality.
 */
final class HashIndexBuilder
{
    private const int HASH_BASE = 33;
    private const int HASH_MOD = 1_000_000_007;

    private TokenNormalizer $normalizer;

    public function __construct()
    {
        // This pass consumes values only; declaration tagging cannot affect its hashes.
        $this->normalizer = new TokenNormalizer(tagDataDeclarations: false);
    }

    /**
     * @param list<SplFileInfo> $files
     */
    public function build(array $files, AbsolutePath $projectRoot, int $minTokens): HashIndexBuildResult
    {
        $filePaths = [];
        $ioPaths = [];
        $candidates = new SaturatingCandidateFilter();

        foreach ($files as $file) {
            $this->observeFileHashes($file, $projectRoot, $minTokens, $filePaths, $ioPaths, $candidates);
            // tokens freed here — not stored
        }

        if (!$candidates->hasCandidates()) {
            return new HashIndexBuildResult($filePaths, $ioPaths, []);
        }

        $hashIndex = $this->collectCandidatePositions($ioPaths, $projectRoot, $minTokens, $candidates);
        $this->pruneUniqueHashes($hashIndex);

        return new HashIndexBuildResult($filePaths, $ioPaths, $hashIndex);
    }

    /**
     * @param list<string> $filePaths modified by reference
     * @param list<string> $ioPaths modified by reference
     */
    private function observeFileHashes(
        SplFileInfo $file,
        AbsolutePath $projectRoot,
        int $minTokens,
        array &$filePaths,
        array &$ioPaths,
        SaturatingCandidateFilter $candidates,
    ): void {
        $ioPath = $file->getPathname();
        $source = @file_get_contents($ioPath);
        if ($source === false) {
            throw new FileSetInspectionFailure([[
                'input' => PathFactory::fromCliArgument($ioPath, $projectRoot),
                'message' => 'Cannot read selected file during duplication hash observation.',
            ]]);
        }

        $tokens = $this->normalizer->normalize($source);
        if ($tokens->count() < $minTokens) {
            return;
        }

        $fileIdx = \count($filePaths);
        $filePaths[] = PathFactory::published(PathFactory::fromCliArgument($ioPath, $projectRoot), $projectRoot)->value();
        $ioPaths[] = $ioPath;

        $this->observeFileCandidates($tokens, $minTokens, $candidates);
    }

    /**
     * Runs the bounded pre-pass over one file without retaining positions.
     */
    private function observeFileCandidates(TokenStream $tokens, int $minTokens, SaturatingCandidateFilter $candidates): void
    {
        $tokenCount = $tokens->count();

        // Compute initial hash for the first window
        $hash = 0;
        $highPow = 1;

        for ($i = 0; $i < $minTokens; $i++) {
            $hash = ($hash * self::HASH_BASE + $this->tokenHash($tokens->values[$i])) % self::HASH_MOD;
            if ($i < $minTokens - 1) {
                $highPow = ($highPow * self::HASH_BASE) % self::HASH_MOD;
            }
        }

        $candidates->observe($hash);

        // Roll the hash forward
        for ($i = 1; $i <= $tokenCount - $minTokens; $i++) {
            $outToken = $this->tokenHash($tokens->values[$i - 1]);
            $inToken = $this->tokenHash($tokens->values[$i + $minTokens - 1]);

            $hash = (($hash - (($outToken * $highPow) % self::HASH_MOD) + self::HASH_MOD) * self::HASH_BASE + $inToken) % self::HASH_MOD;

            $candidates->observe($hash);
        }
    }

    /**
     * Performs the exact second pass. Its token normalizer, rolling hash,
     * minimum-window threshold, and file indexes are identical to the
     * pre-pass. Once a hash is selected, every one of its positions is kept
     * so same-file, cross-file, and 3+ occurrence matches remain observable.
     *
     * @param list<string> $ioPaths
     *
     * @return array<int, list<int>> hash → strictly increasing unique packed positions
     */
    private function collectCandidatePositions(array $ioPaths, AbsolutePath $projectRoot, int $minTokens, SaturatingCandidateFilter $candidates): array
    {
        $index = [];

        foreach ($ioPaths as $fileIdx => $ioPath) {
            $source = @file_get_contents($ioPath);
            if ($source === false) {
                throw new FileSetInspectionFailure([[
                    'input' => PathFactory::fromCliArgument($ioPath, $projectRoot),
                    'message' => 'Cannot read selected file during duplication candidate indexing.',
                ]]);
            }

            $tokens = $this->normalizer->normalize($source);
            if ($tokens->count() < $minTokens) {
                continue;
            }

            $this->addCandidatePositionsToIndex($tokens, $fileIdx, $minTokens, $candidates, $index);
        }

        return $index;
    }

    /**
     * @param array<int, list<int>> $index modified by reference
     */
    private function addCandidatePositionsToIndex(
        TokenStream $tokens,
        int $fileIdx,
        int $minTokens,
        SaturatingCandidateFilter $candidates,
        array &$index,
    ): void {
        $tokenCount = $tokens->count();
        // Validate the farthest stored offset once; the loop can then use packed-base OR cheaply.
        PackedPosition::pack($fileIdx, $tokenCount - $minTokens);
        $packedBase = PackedPosition::pack($fileIdx, 0);
        $hash = 0;
        $highPow = 1;

        for ($i = 0; $i < $minTokens; $i++) {
            $hash = ($hash * self::HASH_BASE + $this->tokenHash($tokens->values[$i])) % self::HASH_MOD;
            if ($i < $minTokens - 1) {
                $highPow = ($highPow * self::HASH_BASE) % self::HASH_MOD;
            }
        }

        if ($candidates->isCandidate($hash)) {
            $index[$hash][] = $packedBase;
        }

        for ($i = 1; $i <= $tokenCount - $minTokens; $i++) {
            $outToken = $this->tokenHash($tokens->values[$i - 1]);
            $inToken = $this->tokenHash($tokens->values[$i + $minTokens - 1]);
            $hash = (($hash - (($outToken * $highPow) % self::HASH_MOD) + self::HASH_MOD) * self::HASH_BASE + $inToken) % self::HASH_MOD;

            if ($candidates->isCandidate($hash)) {
                $index[$hash][] = $packedBase | $i;
            }
        }
    }

    /**
     * Removes hashes with only one occurrence — they cannot be part of a
     * duplicate pair, so keeping them would only bloat pass 2's re-tokenize
     * set and the pair search space.
     *
     * Takes the index by reference and mutates it in place: passing an
     * index this large by value would force a full copy-on-write duplication
     * of the (still largely unpruned) array the moment it's unset()-mutated
     * inside a called function, doubling peak memory for no reason.
     *
     * @param array<int, list<int>> $hashIndex modified by reference
     */
    private function pruneUniqueHashes(array &$hashIndex): void
    {
        foreach ($hashIndex as $hash => $positions) {
            if (\count($positions) < 2) {
                unset($hashIndex[$hash]);
            }
        }
    }

    private function tokenHash(string $value): int
    {
        // Use a simple hash of the token value
        $hash = 0;
        $len = min(\strlen($value), 16);

        for ($i = 0; $i < $len; $i++) {
            $hash = ($hash * 31 + \ord($value[$i])) % self::HASH_MOD;
        }

        return $hash;
    }
}
