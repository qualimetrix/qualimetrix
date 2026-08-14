<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Collection;

use LogicException;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Core\Path\RelativePath;

/** Complete output of the collection phase. */
final readonly class CollectionPhaseOutput
{
    public int $filesAnalyzed;
    public int $filesSkipped;

    /**
     * @param list<RelativePath> $analyzedFiles
     * @param list<FileProcessingResult> $failures
     * @param array<string, list<Suppression>> $suppressions
     * @param array<string, list<ThresholdOverride>> $thresholdOverrides
     * @param array<string, list<ThresholdDiagnostic>> $thresholdDiagnostics
     * @param list<Dependency> $dependencies
     */
    public function __construct(
        public array $analyzedFiles,
        public array $failures,
        public array $suppressions = [],
        public array $thresholdOverrides = [],
        public array $thresholdDiagnostics = [],
        public array $dependencies = [],
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

    public function totalFiles(): int
    {
        return $this->filesAnalyzed + $this->filesSkipped;
    }

    public function hasErrors(): bool
    {
        return $this->filesSkipped > 0;
    }

    /** @param array<string, string> $claimed */
    private static function claimPath(array &$claimed, RelativePath $path, string $state): void
    {
        $value = $path->value();
        if (isset($claimed[$value])) {
            throw new LogicException(\sprintf('Collection path "%s" has multiple terminal states: %s and %s', $value, $claimed[$value], $state));
        }
        $claimed[$value] = $state;
    }
}
