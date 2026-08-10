<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule;

use Qualimetrix\Core\Dependency\CycleInterface;
use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Duplication\DuplicateBlock;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Namespace_\NamespaceTree;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Symbol\MetricSubject;

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
     * Finds the most specific threshold override bound to an exact subject.
     *
     * FileProcessor expands class and property controls to their applicable
     * declaration subjects before transport. Rules therefore never reconstruct
     * ownership from presentation file/line metadata.
     */
    public function getThresholdOverride(string $ruleName, MetricSubject $subject): ?ThresholdOverride
    {
        $bestMatch = null;
        $bestSpecificity = 0;
        $bestSpan = \PHP_INT_MAX;

        foreach ($this->thresholdOverrides as $overrides) {
            foreach ($overrides as $override) {
                if (!$override->matches($ruleName) || $override->subject->toCanonical() !== $subject->toCanonical()) {
                    continue;
                }

                $specificity = $override->controlScope->specificity();
                $span = $override->endLine !== null ? ($override->endLine - $override->line) : \PHP_INT_MAX;

                if ($bestMatch === null
                    || $specificity > $bestSpecificity
                    || ($specificity === $bestSpecificity && $span < $bestSpan)
                ) {
                    $bestMatch = $override;
                    $bestSpecificity = $specificity;
                    $bestSpan = $span;
                }
            }
        }

        return $bestMatch;
    }
}
