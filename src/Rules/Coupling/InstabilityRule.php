<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Coupling;

use AiMessDetector\Core\Metric\MetricName;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\HierarchicalRuleInterface;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleLevel;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;

/**
 * Hierarchical rule that checks instability at class and namespace levels.
 *
 * Instability = Ce / (Ca + Ce), range [0, 1]
 * - 0: maximally stable (only incoming dependencies)
 * - 1: maximally unstable (only outgoing dependencies)
 *
 * Classes/namespaces with high instability are hard to maintain since changes
 * may break many dependents.
 */
final class InstabilityRule extends AbstractRule implements HierarchicalRuleInterface
{
    public const string NAME = 'coupling.instability';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks instability at class and namespace levels';
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
        return [MetricName::COUPLING_INSTABILITY, MetricName::COUPLING_CA, MetricName::COUPLING_CE];
    }

    /**
     * @return list<RuleLevel>
     */
    public function getSupportedLevels(): array
    {
        return [RuleLevel::Class_, RuleLevel::Namespace_];
    }

    /**
     * Analyzes at a specific level.
     *
     * @return list<Violation>
     */
    public function analyzeLevel(RuleLevel $level, AnalysisContext $context): array
    {
        if (!$this->options instanceof InstabilityOptions) {
            return [];
        }

        $levelOptions = $this->options->forLevel($level);
        if (!$levelOptions->isEnabled()) {
            return [];
        }

        return match ($level) {
            RuleLevel::Class_ => $this->analyzeClassLevel($context),
            RuleLevel::Namespace_ => $this->analyzeNamespaceLevel($context),
            default => [],
        };
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        $violations = [];

        foreach ($this->getSupportedLevels() as $level) {
            if ($this->options instanceof InstabilityOptions && $this->options->isLevelEnabled($level)) {
                $violations = [...$violations, ...$this->analyzeLevel($level, $context)];
            }
        }

        return $violations;
    }

    /**
     * @return class-string<InstabilityOptions>
     */
    public static function getOptionsClass(): string
    {
        return InstabilityOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'instability-class-warning' => 'class.max_warning',
            'instability-class-error' => 'class.max_error',
            'instability-ns-warning' => 'namespace.max_warning',
            'instability-ns-error' => 'namespace.max_error',
        ];
    }

    /**
     * @return list<Violation>
     */
    private function analyzeClassLevel(AnalysisContext $context): array
    {
        if (!$this->options instanceof InstabilityOptions) {
            return [];
        }
        $classOptions = $this->options->class;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);

            $instability = $metrics->get(MetricName::COUPLING_INSTABILITY);

            if ($instability === null) {
                continue;
            }

            $instabilityValue = (float) $instability;
            $severity = $classOptions->getSeverity($instabilityValue);

            if ($severity !== null) {
                $ca = (int) ($metrics->get(MetricName::COUPLING_CA) ?? 0);
                $ce = (int) ($metrics->get(MetricName::COUPLING_CE) ?? 0);

                $threshold = $severity === Severity::Error ? $classOptions->maxError : $classOptions->maxWarning;

                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME . '.class',
                    message: \sprintf(
                        'Instability is %.2f (Ca=%d, Ce=%d), exceeds threshold of %.2f. Reduce outgoing dependencies',
                        $instabilityValue,
                        $ca,
                        $ce,
                        $threshold,
                    ),
                    severity: $severity,
                    metricValue: $instabilityValue,
                    level: RuleLevel::Class_,
                    recommendation: \sprintf('Instability: %.2f (threshold: %.2f) — package is highly unstable', $instabilityValue, $threshold),
                    threshold: $threshold,
                );
            }
        }

        return $violations;
    }

    /**
     * @return list<Violation>
     */
    private function analyzeNamespaceLevel(AnalysisContext $context): array
    {
        if (!$this->options instanceof InstabilityOptions) {
            return [];
        }
        $namespaceOptions = $this->options->namespace;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Namespace_) as $nsInfo) {
            // Skip excluded namespaces
            if ($nsInfo->symbolPath->namespace !== null
                && $namespaceOptions->isNamespaceExcluded($nsInfo->symbolPath->namespace)) {
                continue;
            }

            $metrics = $context->metrics->get($nsInfo->symbolPath);

            // Skip namespaces with too few classes
            $classCount = (int) ($metrics->get(MetricName::SIZE_CLASS_COUNT . '.sum') ?? 0);
            if ($classCount < $namespaceOptions->minClassCount) {
                continue;
            }

            $instability = $metrics->get(MetricName::COUPLING_INSTABILITY);

            if ($instability === null) {
                continue;
            }

            $instabilityValue = (float) $instability;
            $severity = $namespaceOptions->getSeverity($instabilityValue);

            if ($severity !== null) {
                $ca = (int) ($metrics->get(MetricName::COUPLING_CA) ?? 0);
                $ce = (int) ($metrics->get(MetricName::COUPLING_CE) ?? 0);

                $threshold = $severity === Severity::Error ? $namespaceOptions->maxError : $namespaceOptions->maxWarning;

                $violations[] = new Violation(
                    location: new Location($nsInfo->file, $nsInfo->line),
                    symbolPath: $nsInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME . '.namespace',
                    message: \sprintf(
                        'Instability is %.2f (Ca=%d, Ce=%d), exceeds threshold of %.2f. Reduce outgoing dependencies',
                        $instabilityValue,
                        $ca,
                        $ce,
                        $threshold,
                    ),
                    severity: $severity,
                    metricValue: $instabilityValue,
                    level: RuleLevel::Namespace_,
                    recommendation: \sprintf('Instability: %.2f (threshold: %.2f) — package is highly unstable', $instabilityValue, $threshold),
                    threshold: $threshold,
                );
            }
        }

        return $violations;
    }
}
