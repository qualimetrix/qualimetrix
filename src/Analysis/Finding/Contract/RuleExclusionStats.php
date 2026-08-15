<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

/**
 * Counts and captures violations suppressed by per-rule `exclude_namespaces`,
 * `exclude_namespace_channels`, or `exclude_paths` (configured under
 * `rules: {<rule-name>: {...}}` in `qmx.yaml`).
 *
 * This mechanism is distinct from the global `exclude_namespaces` / `exclude_paths`
 * filters ({@see \Qualimetrix\Analysis\Finding\Contract\Filter\NamespaceExclusionFilter},
 * {@see \Qualimetrix\Analysis\Finding\Contract\Filter\PathExclusionFilter}), which run later
 * in {@see \Qualimetrix\Reporting\FindingProjection\FindingProjector}: the
 * per-rule variant is applied by {@see RuleExecution} immediately after each rule's
 * `analyze()` call, works for *any* rule regardless of whether its Options class
 * declares such a field ({@see \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory}), and
 * — unlike the global filter — is not exempted for `architecture.*` rules. Without
 * this VO, that suppression was invisible: nothing counted it, nothing reported it.
 */
final readonly class RuleExclusionStats
{
    /**
     * @param array<string, int> $namespaceExclusionsByRule Rule name => violations suppressed by
     *                                                      `exclude_namespaces` or `exclude_namespace_channels`
     * @param array<string, int> $pathExclusionsByRule Rule name => suppressed violation count
     * @param list<Violation> $excludedViolations All violations dropped by any per-rule exclusion, in encounter order.
     *                                            Populated only when the current {@see RuleConfigurationInterface} enables capture from
     *                                            `RuntimeConfigurator`'s `--show-suppressed` policy — the counts above are always collected, but retaining
     *                                            every dropped `Violation` object is opt-in to avoid the memory cost when nothing will display them.
     */
    public function __construct(
        public array $namespaceExclusionsByRule = [],
        public array $pathExclusionsByRule = [],
        public array $excludedViolations = [],
    ) {}

    public function totalNamespaceExclusions(): int
    {
        return array_sum($this->namespaceExclusionsByRule);
    }

    public function totalPathExclusions(): int
    {
        return array_sum($this->pathExclusionsByRule);
    }

    public function isEmpty(): bool
    {
        return $this->namespaceExclusionsByRule === [] && $this->pathExclusionsByRule === [];
    }
}
