<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Run\Collection\FileProcessor;
use Qualimetrix\Core\Symbol\MetricSubject;

final readonly class AnalysisContext
{
    /**
     * The default describes a complete hand-built test context. Production
     * passes the measured judgement explicitly, including counterfactual rule
     * executions that must answer the same scope question as their baseline.
     *
     * @param array<string, list<ThresholdOverride>> $thresholdOverrides Per-file threshold overrides
     */
    public function __construct(
        public MetricRepositoryInterface $metrics,
        public ?DependencyGraphInterface $dependencyGraph = null,
        public ?NamespaceTree $namespaceTree = null,
        public array $thresholdOverrides = [],
        public ProjectScopeJudgement $projectScope = new ProjectScopeJudgement(),
    ) {}

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
                $span = self::span($override);

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

    /** Lines the override covers; one without an end covers everything after it. */
    private static function span(ThresholdOverride $override): int
    {
        return $override->endLine !== null ? $override->endLine - $override->line : \PHP_INT_MAX;
    }
}
