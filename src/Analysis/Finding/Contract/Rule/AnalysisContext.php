<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Run\Collection\FileProcessor;
use Qualimetrix\Core\Symbol\MetricSubject;

final readonly class AnalysisContext
{
    /**
     * @param array<string, list<ThresholdOverride>> $thresholdOverrides Per-file threshold overrides
     * @param bool $coversProjectScope Whether the run analysed the whole project rather than a slice of it
     *
     * `$coversProjectScope` is the answer
     * {@see \Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage}
     * gave for this run's paths, carried here because a rule cannot see them.
     * A rule that reports a configured value as binding to nothing must read
     * it first: "bound nothing" is a fact about the pair (configuration, run
     * scope), and on a narrowed run the same configuration binds perfectly
     * well outside the slice. Every other rule ignores it — a measured
     * threshold is about the declarations the run did analyse, whatever else
     * exists.
     *
     * The default is `true` so that a context built by hand — a unit test, a
     * fixture — reads as a whole-project run and the channels above stay
     * audible. Production builds it twice, both times from a measurement: the
     * pipeline, and the threshold audit re-executing rules against the
     * pipeline's own context.
     */
    public function __construct(
        public MetricRepositoryInterface $metrics,
        public ?DependencyGraphInterface $dependencyGraph = null,
        public ?NamespaceTree $namespaceTree = null,
        public array $thresholdOverrides = [],
        public bool $coversProjectScope = true,
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
