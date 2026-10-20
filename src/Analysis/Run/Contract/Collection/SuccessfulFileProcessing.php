<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Collection;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Serializable facts produced by successful processing of one file. */
final readonly class SuccessfulFileProcessing
{
    /**
     * @param list<CallableWithMetrics> $callableMetrics
     * @param list<ClassLikeDeclaration> $classLikeDeclarations
     * @param array<string, array{subject: MetricSubject, metrics: MetricBag, line: int, start: int}> $classMetrics
     * @param array<string, array{symbolPath: SymbolPath, metrics: MetricBag, line: int}> $namespaceMetrics
     * @param list<Dependency> $dependencies
     * @param list<Suppression> $suppressions
     * @param list<ThresholdOverride> $thresholdOverrides
     * @param list<ThresholdDiagnostic> $thresholdDiagnostics
     *
      * @qmx-threshold code-smell.long-parameter-list 10 -- Metrics, declaration identities and directives originate in one successful file collection and must be attributed to that same file.
      * @qmx-threshold code-smell.constructor-overinjection 10 -- Metrics, declaration identities and directives originate in one successful file collection and must be attributed to that same file.
     */
    public function __construct(
        public MetricBag $fileBag,
        public array $classLikeDeclarations,
        public array $callableMetrics = [],
        public array $classMetrics = [],
        public array $namespaceMetrics = [],
        public array $dependencies = [],
        public array $suppressions = [],
        public array $thresholdOverrides = [],
        public array $thresholdDiagnostics = [],
    ) {}
}
