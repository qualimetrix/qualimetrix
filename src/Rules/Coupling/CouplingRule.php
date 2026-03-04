<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Coupling;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\HierarchicalRuleInterface;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleLevel;
use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;
use InvalidArgumentException;

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
final class CouplingRule extends AbstractRule implements HierarchicalRuleInterface
{
    public const string NAME = 'coupling';
    private const string METRIC_INSTABILITY = 'instability';
    private const string METRIC_CA = 'ca';
    private const string METRIC_CE = 'ce';
    private const string METRIC_CBO = 'cbo';

    public function __construct(
        RuleOptionsInterface $options,
    ) {
        if (!$options instanceof CouplingOptions) {
            throw new InvalidArgumentException(
                \sprintf('Expected %s, got %s', CouplingOptions::class, $options::class),
            );
        }
        parent::__construct($options);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks instability (coupling) at class and namespace levels';
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
        return [self::METRIC_INSTABILITY, self::METRIC_CA, self::METRIC_CE, self::METRIC_CBO];
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
        if (!$this->options instanceof CouplingOptions) {
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
     * Legacy analyze method for backward compatibility.
     *
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        // When called directly (not via analyzeLevel), analyze all enabled levels
        $violations = [];

        foreach ($this->getSupportedLevels() as $level) {
            if ($this->options instanceof CouplingOptions && $this->options->isLevelEnabled($level)) {
                $violations = [...$violations, ...$this->analyzeLevel($level, $context)];
            }
        }

        return $violations;
    }

    /**
     * @return class-string<CouplingOptions>
     */
    public static function getOptionsClass(): string
    {
        return CouplingOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'coupling-class-warning' => 'class.max_instability_warning',
            'coupling-class-error' => 'class.max_instability_error',
            'coupling-ns-warning' => 'namespace.max_instability_warning',
            'coupling-ns-error' => 'namespace.max_instability_error',
            'cbo-class-warning' => 'class.cbo_warning_threshold',
            'cbo-class-error' => 'class.cbo_error_threshold',
            'cbo-ns-warning' => 'namespace.cbo_warning_threshold',
            'cbo-ns-error' => 'namespace.cbo_error_threshold',
        ];
    }

    /**
     * @return list<Violation>
     */
    private function analyzeClassLevel(AnalysisContext $context): array
    {
        if (!$this->options instanceof CouplingOptions) {
            return [];
        }
        $classOptions = $this->options->class;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);

            // Check CBO
            $cbo = $metrics->get(self::METRIC_CBO);
            if ($cbo !== null) {
                $cboValue = (int) $cbo;
                $cboViolation = $this->checkCbo($cboValue, $classInfo, $metrics, $classOptions);
                if ($cboViolation !== null) {
                    $violations[] = $cboViolation;
                }
            }

            // Check Instability
            $instability = $metrics->get(self::METRIC_INSTABILITY);

            if ($instability === null) {
                continue;
            }

            $instabilityValue = (float) $instability;
            $severity = $classOptions->getSeverity($instabilityValue);

            if ($severity !== null) {
                $ca = (int) ($metrics->get(self::METRIC_CA) ?? 0);
                $ce = (int) ($metrics->get(self::METRIC_CE) ?? 0);

                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
                    ruleName: $this->getName(),
                    message: \sprintf(
                        'Instability is %.2f (Ca=%d, Ce=%d), exceeds threshold of %.2f. Reduce outgoing dependencies',
                        $instabilityValue,
                        $ca,
                        $ce,
                        $severity === Severity::Error ? $classOptions->maxInstabilityError : $classOptions->maxInstabilityWarning,
                    ),
                    severity: $severity,
                    metricValue: $instabilityValue,
                    level: RuleLevel::Class_,
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
        if (!$this->options instanceof CouplingOptions) {
            return [];
        }
        $namespaceOptions = $this->options->namespace;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Namespace_) as $nsInfo) {
            $metrics = $context->metrics->get($nsInfo->symbolPath);

            // Check CBO
            $cbo = $metrics->get(self::METRIC_CBO);
            if ($cbo !== null) {
                $cboValue = (int) $cbo;
                $cboViolation = $this->checkCbo($cboValue, $nsInfo, $metrics, $namespaceOptions);
                if ($cboViolation !== null) {
                    $violations[] = $cboViolation;
                }
            }

            // Check Instability
            $instability = $metrics->get(self::METRIC_INSTABILITY);

            if ($instability === null) {
                continue;
            }

            $instabilityValue = (float) $instability;
            $severity = $namespaceOptions->getSeverity($instabilityValue);

            if ($severity !== null) {
                $ca = (int) ($metrics->get(self::METRIC_CA) ?? 0);
                $ce = (int) ($metrics->get(self::METRIC_CE) ?? 0);

                $violations[] = new Violation(
                    location: new Location($nsInfo->file, $nsInfo->line),
                    symbolPath: $nsInfo->symbolPath,
                    ruleName: $this->getName(),
                    message: \sprintf(
                        'Instability is %.2f (Ca=%d, Ce=%d), exceeds threshold of %.2f. Reduce outgoing dependencies',
                        $instabilityValue,
                        $ca,
                        $ce,
                        $severity === Severity::Error ? $namespaceOptions->maxInstabilityError : $namespaceOptions->maxInstabilityWarning,
                    ),
                    severity: $severity,
                    metricValue: $instabilityValue,
                    level: RuleLevel::Namespace_,
                );
            }
        }

        return $violations;
    }

    /**
     * Checks CBO (Coupling Between Objects) threshold for a symbol.
     */
    private function checkCbo(
        int $cbo,
        \AiMessDetector\Core\Symbol\SymbolInfo $symbolInfo,
        MetricBag $metrics,
        ClassCouplingOptions|NamespaceCouplingOptions $options,
    ): ?Violation {
        $ca = (int) ($metrics->get(self::METRIC_CA) ?? 0);
        $ce = (int) ($metrics->get(self::METRIC_CE) ?? 0);

        // Namespace has type=null, class has type=<ClassName>
        $isNamespace = $symbolInfo->symbolPath->type === null && $symbolInfo->symbolPath->member === null;

        if ($cbo > $options->cboErrorThreshold) {
            return new Violation(
                location: new Location($symbolInfo->file, $symbolInfo->line),
                symbolPath: $symbolInfo->symbolPath,
                ruleName: $this->getName(),
                message: \sprintf(
                    'CBO (Coupling Between Objects) is %d (Ca=%d, Ce=%d), exceeds threshold of %d. Reduce dependencies to lower coupling',
                    $cbo,
                    $ca,
                    $ce,
                    $options->cboErrorThreshold,
                ),
                severity: Severity::Error,
                metricValue: (float) $cbo,
                level: $isNamespace ? RuleLevel::Namespace_ : RuleLevel::Class_,
            );
        }

        if ($cbo > $options->cboWarningThreshold) {
            return new Violation(
                location: new Location($symbolInfo->file, $symbolInfo->line),
                symbolPath: $symbolInfo->symbolPath,
                ruleName: $this->getName(),
                message: \sprintf(
                    'CBO (Coupling Between Objects) is %d (Ca=%d, Ce=%d), exceeds threshold of %d. Reduce dependencies to lower coupling',
                    $cbo,
                    $ca,
                    $ce,
                    $options->cboWarningThreshold,
                ),
                severity: Severity::Warning,
                metricValue: (float) $cbo,
                level: $isNamespace ? RuleLevel::Namespace_ : RuleLevel::Class_,
            );
        }

        return null;
    }
}
