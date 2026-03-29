<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule;

use Qualimetrix\Core\Dependency\CycleInterface;
use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Duplication\DuplicateBlock;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Namespace_\NamespaceTree;
use Qualimetrix\Core\Suppression\ThresholdOverride;

final readonly class AnalysisContext
{
    /**
     * @param array<string, mixed> $ruleOptions
     * @param list<CycleInterface> $cycles Detected circular dependency cycles
     * @param list<DuplicateBlock> $duplicateBlocks Detected code duplication blocks
     * @param array<string, list<ThresholdOverride>> $thresholdOverrides Per-file threshold overrides
     */
    public function __construct(
        public MetricRepositoryInterface $metrics,
        public array $ruleOptions = [],
        public ?DependencyGraphInterface $dependencyGraph = null,
        public array $cycles = [],
        public array $duplicateBlocks = [],
        public ?NamespaceTree $namespaceTree = null,
        public array $thresholdOverrides = [],
    ) {}

    /**
     * Gets options for a specific rule.
     *
     * @return array<string, mixed>
     */
    public function getOptionsForRule(string $ruleName): array
    {
        /** @var array<string, mixed> */
        return $this->ruleOptions[$ruleName] ?? [];
    }

    /**
     * Finds the most specific threshold override for a rule, file, and line.
     *
     * When multiple overrides match (e.g., class-level and method-level),
     * selects the one with the smallest scope (endLine - line span).
     * This ensures method-level overrides take priority over class-level ones.
     *
     * Overrides with null endLine (unbounded scope) are treated as having
     * infinite span, so any bounded override will win over an unbounded one.
     */
    public function getThresholdOverride(string $ruleName, string $file, int $line): ?ThresholdOverride
    {
        if (!isset($this->thresholdOverrides[$file])) {
            return null;
        }

        $bestMatch = null;
        $bestSpan = \PHP_INT_MAX;

        foreach ($this->thresholdOverrides[$file] as $override) {
            if (!$override->matches($ruleName)) {
                continue;
            }

            if ($line < $override->line || ($override->endLine !== null && $line > $override->endLine)) {
                continue;
            }

            $span = $override->endLine !== null ? ($override->endLine - $override->line) : \PHP_INT_MAX;

            if ($bestMatch === null || $span < $bestSpan) {
                $bestMatch = $override;
                $bestSpan = $span;
            }
        }

        return $bestMatch;
    }
}
