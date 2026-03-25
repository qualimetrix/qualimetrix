<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline;

use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\PathsConfiguration;

/**
 * Fully resolved configuration after pipeline processing.
 */
final readonly class ResolvedConfiguration
{
    /**
     * @param array<string, mixed> $ruleOptions
     * @param array<string, mixed> $computedMetrics
     * @param list<string> $appliedSources Names of configuration sources that contributed values
     */
    public function __construct(
        public PathsConfiguration $paths,
        public AnalysisConfiguration $analysis,
        public array $ruleOptions,
        public array $computedMetrics = [],
        public array $appliedSources = [],
    ) {}
}
