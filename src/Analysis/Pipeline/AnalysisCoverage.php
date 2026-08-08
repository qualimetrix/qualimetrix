<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Pipeline;

use LogicException;
use Qualimetrix\Core\Path\RelativePath;

/**
 * Canonical coverage of a complete analysis run.
 *
 * Every discovered path has exactly one terminal state. Generated exclusions
 * are intentional and therefore keep the run complete; failures do not.
 */
final readonly class AnalysisCoverage
{
    /** @var list<RelativePath> */
    public array $analyzedFiles;

    /** @var list<RelativePath> */
    public array $generatedExcludedFiles;

    /** @var list<AnalysisFailure> */
    public array $failures;

    /**
     * @param list<RelativePath> $analyzedFiles
     * @param list<RelativePath> $generatedExcludedFiles
     * @param list<AnalysisFailure> $failures
     */
    public function __construct(
        array $analyzedFiles,
        array $generatedExcludedFiles,
        array $failures,
    ) {
        usort($analyzedFiles, self::comparePaths(...));
        usort($generatedExcludedFiles, self::comparePaths(...));
        usort(
            $failures,
            static fn(AnalysisFailure $left, AnalysisFailure $right): int => strcmp(
                $left->path->value(),
                $right->path->value(),
            ),
        );

        $this->analyzedFiles = $analyzedFiles;
        $this->generatedExcludedFiles = $generatedExcludedFiles;
        $this->failures = $failures;

        $terminalPaths = [];
        foreach ($this->analyzedFiles as $path) {
            self::claimPath($terminalPaths, $path, 'analyzed');
        }
        foreach ($this->generatedExcludedFiles as $path) {
            self::claimPath($terminalPaths, $path, 'generated-excluded');
        }
        foreach ($this->failures as $failure) {
            self::claimPath($terminalPaths, $failure->path, 'failure');
        }
    }

    public function discoveredFiles(): int
    {
        return $this->analyzedFilesCount()
            + $this->generatedExcludedFilesCount()
            + $this->failedFilesCount();
    }

    public function analyzedFilesCount(): int
    {
        return \count($this->analyzedFiles);
    }

    public function generatedExcludedFilesCount(): int
    {
        return \count($this->generatedExcludedFiles);
    }

    public function failedFilesCount(): int
    {
        return \count($this->failures);
    }

    public function skippedFilesCount(): int
    {
        return $this->generatedExcludedFilesCount() + $this->failedFilesCount();
    }

    public function isComplete(): bool
    {
        return $this->failures === [];
    }

    public function merge(self $other): self
    {
        return new self(
            [...$this->analyzedFiles, ...$other->analyzedFiles],
            [...$this->generatedExcludedFiles, ...$other->generatedExcludedFiles],
            [...$this->failures, ...$other->failures],
        );
    }

    /** @param array<string, string> $claimed */
    private static function claimPath(array &$claimed, RelativePath $path, string $state): void
    {
        $value = $path->value();
        if (isset($claimed[$value])) {
            throw new LogicException(\sprintf(
                'Analysis path "%s" has multiple terminal states: %s and %s',
                $value,
                $claimed[$value],
                $state,
            ));
        }

        $claimed[$value] = $state;
    }

    private static function comparePaths(RelativePath $left, RelativePath $right): int
    {
        return strcmp($left->value(), $right->value());
    }
}
