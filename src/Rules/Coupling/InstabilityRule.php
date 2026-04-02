<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Coupling;

use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\HierarchicalRuleInterface;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Rule\RuleLevel;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\AbstractRule;

/**
 * Hierarchical rule that checks instability at class and namespace levels.
 *
 * Instability = Ce / (Ca + Ce), range [0, 1]
 * - 0: maximally stable (only incoming dependencies)
 * - 1: maximally unstable (only outgoing dependencies)
 *
 * Classes/namespaces with high instability are fragile — they depend on many
 * other components, so changes in dependencies may break them.
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

            // Skip classes with insufficient afferent coupling.
            // Classes with very few dependents (low Ca) have high instability by definition,
            // which is architecturally expected for concrete implementation classes.
            $caRaw = $metrics->get(MetricName::COUPLING_CA);
            $ca = $caRaw !== null ? (int) $caRaw : 0;
            if ($ca < $classOptions->minAfferent) {
                continue;
            }

            $instabilityValue = (float) $instability;

            /** @var ClassInstabilityOptions $effectiveClassOptions */
            $effectiveClassOptions = $this->getEffectiveOptions($context, $classOptions, $classInfo->file, $classInfo->line ?? 1);
            $severity = $effectiveClassOptions->getSeverity($instabilityValue);

            if ($severity !== null) {
                $ce = (int) ($metrics->get(MetricName::COUPLING_CE) ?? 0);

                $threshold = $severity === Severity::Error ? $effectiveClassOptions->maxError : $effectiveClassOptions->maxWarning;

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

            // Skip namespaces with insufficient afferent coupling.
            // Namespaces with very few dependents have high instability by definition.
            $ca = (int) ($metrics->get(MetricName::COUPLING_CA) ?? 0);
            if ($ca < $namespaceOptions->minAfferent) {
                continue;
            }

            $instabilityValue = (float) $instability;

            /** @var NamespaceInstabilityOptions $effectiveNsOptions */
            $effectiveNsOptions = $this->getEffectiveOptions($context, $namespaceOptions, $nsInfo->file, $nsInfo->line ?? 1);
            $severity = $effectiveNsOptions->getSeverity($instabilityValue);

            if ($severity !== null) {
                $ce = (int) ($metrics->get(MetricName::COUPLING_CE) ?? 0);

                $threshold = $severity === Severity::Error ? $effectiveNsOptions->maxError : $effectiveNsOptions->maxWarning;

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
