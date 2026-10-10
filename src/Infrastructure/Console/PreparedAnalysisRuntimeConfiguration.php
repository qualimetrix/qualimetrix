<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfiguration;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ResolvedArchitecturePolicyInterface;
use Qualimetrix\Core\Pattern\NamespacePattern;

/** Complete pure analysis preparation, committed only after every owner accepts. */
final readonly class PreparedAnalysisRuntimeConfiguration
{
    /** @param list<NamespacePattern> $frameworkNamespaces */
    public function __construct(
        public FindingConfiguration $findingConfiguration,
        public LcomCollectionConfiguration $lcomConfiguration,
        public ResolvedArchitecturePolicyInterface $architecturePolicy,
        public ResolvedComputedMetricDefinitions $computedMetrics,
        public array $frameworkNamespaces,
        public RuleChannelRegistryInterface $channels,
    ) {}
}
