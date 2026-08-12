<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\ThresholdDiagnostic;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Symbol\SymbolPath;

/** The complete successful output of one file's collection pass. */
final readonly class CollectedFileData
{
    /**
     * @param list<CallableWithMetrics> $callableMetrics
     * @param array<string, array{subject: \Qualimetrix\Core\Symbol\MetricSubject, metrics: MetricBag, line: int}> $classMetrics
     * @param array<string, array{symbolPath: SymbolPath, metrics: MetricBag, line: int}> $namespaceMetrics
     * @param list<Dependency> $dependencies
     * @param list<Suppression> $suppressions
     * @param list<ThresholdOverride> $thresholdOverrides
     * @param list<ThresholdDiagnostic> $thresholdDiagnostics
     */
    public function __construct(
        public MetricBag $fileBag,
        public array $callableMetrics = [],
        public array $classMetrics = [],
        public array $namespaceMetrics = [],
        public array $dependencies = [],
        public array $suppressions = [],
        public array $thresholdOverrides = [],
        public array $thresholdDiagnostics = [],
    ) {}
}
