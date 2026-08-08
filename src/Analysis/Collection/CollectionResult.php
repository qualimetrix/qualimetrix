<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection;

use LogicException;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\ThresholdDiagnostic;
use Qualimetrix\Core\Suppression\ThresholdOverride;

/**
 * Result of the collection phase.
 *
 * Contains summary information about files processed during collection.
 * Dependencies are returned separately (via CollectionPhaseOutput) because they have
 * a shorter lifecycle — they are consumed during graph building and should not
 * persist for the rest of the pipeline.
 */
final readonly class CollectionResult
{
    public int $filesAnalyzed;

    public int $filesSkipped;

    /**
     * @param list<RelativePath> $analyzedFiles Successfully analyzed paths
     * @param list<FileProcessingResult> $failures Failed results with typed causes
     * @param array<string, list<Suppression>> $suppressions Per-file suppression tags (file => suppressions)
     * @param array<string, list<ThresholdOverride>> $thresholdOverrides Per-file threshold overrides (file => overrides)
     * @param array<string, list<ThresholdDiagnostic>> $thresholdDiagnostics Per-file diagnostics for invalid `@qmx-threshold` annotations
     */
    public function __construct(
        public array $analyzedFiles,
        public array $failures,
        public array $suppressions = [],
        public array $thresholdOverrides = [],
        public array $thresholdDiagnostics = [],
    ) {
        $terminalPaths = [];
        foreach ($this->analyzedFiles as $path) {
            self::claimPath($terminalPaths, $path, 'analyzed');
        }
        foreach ($this->failures as $failure) {
            if ($failure->isSuccessful()) {
                throw new LogicException('Collection failure list contains a successful result');
            }
            self::claimPath($terminalPaths, $failure->filePath, 'failure');
        }

        $this->filesAnalyzed = \count($this->analyzedFiles);
        $this->filesSkipped = \count($this->failures);
    }

    /**
     * Returns total number of files processed (analyzed + skipped).
     */
    public function totalFiles(): int
    {
        return $this->filesAnalyzed + $this->filesSkipped;
    }

    /**
     * Returns whether any files failed to process.
     */
    public function hasErrors(): bool
    {
        return $this->filesSkipped > 0;
    }

    /** @param array<string, string> $claimed */
    private static function claimPath(array &$claimed, RelativePath $path, string $state): void
    {
        $value = $path->value();
        if (isset($claimed[$value])) {
            throw new LogicException(\sprintf(
                'Collection path "%s" has multiple terminal states: %s and %s',
                $value,
                $claimed[$value],
                $state,
            ));
        }

        $claimed[$value] = $state;
    }
}
