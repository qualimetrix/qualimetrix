<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Duplication;

use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use SplFileInfo;

/**
 * Pass 1 of the Rabin-Karp duplication scan: stream each file once,
 * tokenize it, compute rolling hashes for every {@see minTokens}-wide
 * window, and record them in a hash index — then discard the tokens
 * immediately (they are not retained past this method).
 *
 * Also performs the index pruning step: hashes with a single occurrence
 * cannot be part of a duplicate pair and are dropped before pass 2, which
 * typically removes ~75% of entries.
 *
 * Kept separate from match verification ({@see DuplicateBlockFinder}) so
 * that neither method needs to hold both the full token stream of every
 * file and the hash index at once — see {@see DuplicationDetector} for the
 * memory-optimization rationale this split preserves.
 */
final class HashIndexBuilder
{
    private const int HASH_BASE = 33;
    private const int HASH_MOD = 1_000_000_007;

    private TokenNormalizer $normalizer;

    public function __construct()
    {
        // Data-declaration tagging is switched off here on purpose: this pass hashes
        // token values and throws the tokens away, and `isData` is only ever read in
        // pass 2 ({@see DuplicateBlockFinder}). Tagging would rebuild the token array
        // for every file containing a constant or property array and buy nothing.
        $this->normalizer = new TokenNormalizer(tagDataDeclarations: false);
    }

    /**
     * @param list<SplFileInfo> $files
     */
    public function build(array $files, AbsolutePath $projectRoot, int $minTokens): HashIndexBuildResult
    {
        $filePaths = [];
        $ioPaths = [];
        $hashIndex = [];

        foreach ($files as $file) {
            $this->indexFile($file, $projectRoot, $minTokens, $filePaths, $ioPaths, $hashIndex);
            // tokens freed here — not stored
        }

        $this->pruneUniqueHashes($hashIndex);

        return new HashIndexBuildResult($filePaths, $ioPaths, $hashIndex);
    }

    /**
     * @param list<string> $filePaths modified by reference
     * @param list<string> $ioPaths modified by reference
     * @param array<int, list<int>> $hashIndex modified by reference
     */
    private function indexFile(
        SplFileInfo $file,
        AbsolutePath $projectRoot,
        int $minTokens,
        array &$filePaths,
        array &$ioPaths,
        array &$hashIndex,
    ): void {
        $ioPath = $file->getPathname();
        $source = @file_get_contents($ioPath);
        if ($source === false) {
            return;
        }

        $tokens = $this->normalizer->normalize($source);
        if (\count($tokens) < $minTokens) {
            return;
        }

        $fileIdx = \count($filePaths);
        $filePaths[] = PathFactory::bestEffortRelative($ioPath, $projectRoot)->value();
        $ioPaths[] = $ioPath;

        $this->addFileHashesToIndex($tokens, $fileIdx, $minTokens, $hashIndex);
    }

    /**
     * Computes rolling hashes for a single file's tokens and adds them to the index.
     *
     * @param list<NormalizedToken> $tokens
     * @param array<int, list<int>> $index modified by reference
     */
    private function addFileHashesToIndex(array $tokens, int $fileIdx, int $minTokens, array &$index): void
    {
        $tokenCount = \count($tokens);

        $packedBase = PackedPosition::pack($fileIdx, 0);

        // Compute initial hash for the first window
        $hash = 0;
        $highPow = 1;

        for ($i = 0; $i < $minTokens; $i++) {
            $hash = ($hash * self::HASH_BASE + $this->tokenHash($tokens[$i])) % self::HASH_MOD;
            if ($i < $minTokens - 1) {
                $highPow = ($highPow * self::HASH_BASE) % self::HASH_MOD;
            }
        }

        $index[$hash][] = $packedBase; // offset 0

        // Roll the hash forward
        for ($i = 1; $i <= $tokenCount - $minTokens; $i++) {
            $outToken = $this->tokenHash($tokens[$i - 1]);
            $inToken = $this->tokenHash($tokens[$i + $minTokens - 1]);

            $hash = (($hash - (($outToken * $highPow) % self::HASH_MOD) + self::HASH_MOD) * self::HASH_BASE + $inToken) % self::HASH_MOD;

            $index[$hash][] = $packedBase | $i;
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
}
