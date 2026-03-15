<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Coupling;

use AiMessDetector\Core\Metric\MetricName;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;

/**
 * Rule that checks ClassRank (PageRank on dependency graph) at class level.
 *
 * ClassRank identifies the most "important" classes in the codebase by analyzing
 * the dependency graph using the PageRank algorithm. Classes with high ClassRank
 * are critical hubs where changes have wide-reaching impact.
 */
final class ClassRankRule extends AbstractRule
{
    public const string NAME = 'coupling.class-rank';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks ClassRank (PageRank on dependency graph) to identify critical hub classes';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Coupling;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::COUPLING_CLASS_RANK];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof ClassRankOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);
            $classRank = $metrics->get(MetricName::COUPLING_CLASS_RANK);

            if ($classRank === null) {
                continue;
            }

            $rankValue = (float) $classRank;
            $severity = $this->options->getSeverity($rankValue);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error
                    ? $this->options->error
                    : $this->options->warning;

                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME,
                    message: \sprintf(
                        'ClassRank is %.4f, exceeds threshold of %.4f. This class is a critical hub — changes have wide impact',
                        $rankValue,
                        $threshold,
                    ),
                    severity: $severity,
                    metricValue: $rankValue,
                    recommendation: \sprintf('ClassRank: %.4f (max %.4f) — coupling hotspot, many depend on this', $rankValue, $threshold),
                    threshold: $threshold,
                );
            }
        }

        return $violations;
    }

    /**
     * @return class-string<ClassRankOptions>
     */
    public static function getOptionsClass(): string
    {
        return ClassRankOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'class-rank-warning' => 'warning',
            'class-rank-error' => 'error',
        ];
    }
}
