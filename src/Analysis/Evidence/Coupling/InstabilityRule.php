<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

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
#[CliAlias('instability-class-warning', 'class.max_warning')]
#[CliAlias('instability-class-error', 'class.max_error')]
#[CliAlias('instability-ns-warning', 'namespace.max_warning')]
#[CliAlias('instability-ns-error', 'namespace.max_error')]
final class InstabilityRule extends AbstractRule implements HierarchicalRuleInterface
{
    public const string NAME = 'coupling.instability';
    public const string DOCS_PAGE = 'rules/coupling.md';

    public const int REMEDIATION_MINUTES = 30;
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
     * @return list<SymbolLevel>
     */
    public function getSupportedLevels(): array
    {
        return [SymbolLevel::Class_, SymbolLevel::Namespace_];
    }

    /**
     * Analyzes at a specific level.
     *
     * @return list<Violation>
     */
    public function analyzeLevel(SymbolLevel $level, AnalysisContext $context): array
    {
        if (!$this->options instanceof InstabilityOptions) {
            return [];
        }

        $levelOptions = $this->options->forLevel($level);
        if (!$levelOptions->isEnabled()) {
            return [];
        }

        return match ($level) {
            SymbolLevel::Class_ => $this->analyzeClassLevel($context),
            SymbolLevel::Namespace_ => $this->analyzeNamespaceLevel($context),
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
     * Both instability channels report the instability value
     * (`$instabilityValue` — see {@see analyzeClassLevel()} and
     * {@see analyzeNamespaceLevel()}) as `metricValue`, judged worse the
     * higher it goes: {@see ClassInstabilityOptions::getSeverity()}'s
     * `$instability >= $this->maxError` (line 61) / `$instability >=
     * $this->maxWarning` (line 65) for the class channel, and
     * {@see NamespaceInstabilityOptions::getSeverity()}'s `$instability >=
     * $this->maxError` (line 62) / `$instability >= $this->maxWarning`
     * (line 66) for the namespace channel.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            ViolationChannel::leveled(self::NAME, SymbolLevel::Class_)->toKey() => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_),
            ViolationChannel::leveled(self::NAME, SymbolLevel::Namespace_)->toKey() => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Namespace_),
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

        foreach ($context->metrics->allDeclarations() as $classInfo) {
            $violation = $this->classViolation($classInfo, $context, $classOptions);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    private function classViolation(
        SymbolInfo $classInfo,
        AnalysisContext $context,
        ClassInstabilityOptions $options,
    ): ?Violation {
        $subject = $classInfo->subject ?? throw new LogicException('Instability class findings require an exact class declaration subject');
        if ($subject->toSymbolPath()->getType() !== SymbolType::Class_) {
            return null;
        }

        $metrics = $context->metrics->get($subject->toSymbolPath());
        $instability = $metrics->get(MetricName::COUPLING_INSTABILITY);
        if ($instability === null) {
            return null;
        }

        $ca = (int) ($metrics->get(MetricName::COUPLING_CA) ?? 0);
        if ($ca < $options->minAfferent) {
            return null;
        }

        $instabilityValue = (float) $instability;
        /** @var ClassInstabilityOptions $effectiveOptions */
        $effectiveOptions = $this->getEffectiveOptions($context, $options, $subject);
        $severity = $effectiveOptions->getSeverity($instabilityValue);
        if ($severity === null) {
            return null;
        }

        $ce = (int) ($metrics->get(MetricName::COUPLING_CE) ?? 0);
        $threshold = $severity === Severity::Error ? $effectiveOptions->maxError : $effectiveOptions->maxWarning;

        return new Violation(
            location: new Location($classInfo->file, $classInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            violationCode: ViolationChannel::leveled(self::NAME, SymbolLevel::Class_)->violationCode,
            message: \sprintf(
                'Instability is %.2f (Ca=%d, Ce=%d), exceeds threshold of %.2f. Reduce outgoing dependencies',
                $instabilityValue,
                $ca,
                $ce,
                $threshold,
            ),
            severity: $severity,
            metricValue: $instabilityValue,
            level: SymbolLevel::Class_,
            recommendation: \sprintf('Instability: %.2f (threshold: %.2f) — package is highly unstable', $instabilityValue, $threshold),
            threshold: $threshold,
        );
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
            $subject = $nsInfo->subject ?? MetricSubject::aggregate($nsInfo->symbolPath);
            $metrics = $context->metrics->get($nsInfo->symbolPath);

            // Skip namespaces with too few classes
            $classCount = (int) ($metrics->get(MetricName::agg(MetricName::SIZE_CLASS_COUNT, AggregationStrategy::Sum)) ?? 0);
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
            $effectiveNsOptions = $this->getEffectiveOptions($context, $namespaceOptions, $subject);
            $severity = $effectiveNsOptions->getSeverity($instabilityValue);

            if ($severity !== null) {
                $ce = (int) ($metrics->get(MetricName::COUPLING_CE) ?? 0);

                $threshold = $severity === Severity::Error ? $effectiveNsOptions->maxError : $effectiveNsOptions->maxWarning;

                $violations[] = new Violation(
                    location: new Location($nsInfo->file, $nsInfo->line),
                    subject: $subject,
                    symbolPath: $nsInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: ViolationChannel::leveled(self::NAME, SymbolLevel::Namespace_)->violationCode,
                    message: \sprintf(
                        'Instability is %.2f (Ca=%d, Ce=%d), exceeds threshold of %.2f. Reduce outgoing dependencies',
                        $instabilityValue,
                        $ca,
                        $ce,
                        $threshold,
                    ),
                    severity: $severity,
                    metricValue: $instabilityValue,
                    level: SymbolLevel::Namespace_,
                    recommendation: \sprintf('Instability: %.2f (threshold: %.2f) — package is highly unstable', $instabilityValue, $threshold),
                    threshold: $threshold,
                );
            }
        }

        return $violations;
    }

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
