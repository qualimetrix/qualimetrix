<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract;

use Qualimetrix\Analysis\Finding\Contract\RuleSelection;

/**
 * Fully resolved configuration after pipeline processing.
 *
 * Feature-owned configuration is exposed as an ordered document; this
 * transitional DTO does not materialize feature configuration or warnings.
 *
 * @qmx-threshold code-smell.constructor-overinjection warning=10 error=10 -- Transitional resolved configuration exposes nine independently owned resolved values; grouping them would recreate the opaque configuration aggregate being dismantled, and the inclusive threshold of 10 rejects a tenth field.
 * @qmx-threshold code-smell.long-parameter-list warning=10 error=10 -- Transitional resolved configuration exposes nine independently owned resolved values; grouping them would recreate the opaque configuration aggregate being dismantled, and the inclusive threshold of 10 rejects a tenth field.
 */
final readonly class TransitionalResolvedConfiguration
{
    /**
     * @param list<string> $paths
     * @param list<string> $pathExcludes
     * @param array<string, mixed> $ruleOptions
     * @param list<string> $appliedSources Names of configuration sources that contributed values
     */
    public function __construct(
        public array $paths,
        public array $pathExcludes,
        public TransitionalRuntimeConfiguration $runtime,
        public array $ruleOptions,
        public ConfigurationDocument $document,
        public RuleSelection $ruleSelection = new RuleSelection(),
        public OutputFormat $outputFormat = new OutputFormat(OutputFormat::DEFAULT),
        public ResolvedFindingExclusions $findingExclusions = new ResolvedFindingExclusions(),
        public array $appliedSources = [],
    ) {}
}
