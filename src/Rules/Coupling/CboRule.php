<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Coupling;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\HierarchicalRuleInterface;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleLevel;
use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;
use InvalidArgumentException;

/**
 * Hierarchical rule that checks CBO (Coupling Between Objects) at class and namespace levels.
 *
 * CBO = Ca + Ce (afferent + efferent coupling)
 * - Low CBO (<=14): weakly coupled, easy to test
 * - Medium CBO (15-20): acceptable
 * - High CBO (>20): tightly coupled, hard to isolate
 */
final class CboRule extends AbstractRule implements HierarchicalRuleInterface
{
    public const string NAME = 'coupling.cbo';
    private const string METRIC_CBO = 'cbo';
    private const string METRIC_CA = 'ca';
    private const string METRIC_CE = 'ce';

    public function __construct(
        RuleOptionsInterface $options,
    ) {
        if (!$options instanceof CboOptions) {
            throw new InvalidArgumentException(
                \sprintf('Expected %s, got %s', CboOptions::class, $options::class),
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
        return 'Checks CBO (Coupling Between Objects) at class and namespace levels';
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
        return [self::METRIC_CBO, self::METRIC_CA, self::METRIC_CE];
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
        if (!$this->options instanceof CboOptions) {
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
        $violations = [];

        foreach ($this->getSupportedLevels() as $level) {
            if ($this->options instanceof CboOptions && $this->options->isLevelEnabled($level)) {
                $violations = [...$violations, ...$this->analyzeLevel($level, $context)];
            }
        }

        return $violations;
    }

    /**
     * @return class-string<CboOptions>
     */
    public static function getOptionsClass(): string
    {
        return CboOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'cbo-class-warning' => 'class.warning',
            'cbo-class-error' => 'class.error',
            'cbo-ns-warning' => 'namespace.warning',
            'cbo-ns-error' => 'namespace.error',
        ];
    }

    /**
     * @return list<Violation>
     */
    private function analyzeClassLevel(AnalysisContext $context): array
    {
        if (!$this->options instanceof CboOptions) {
            return [];
        }
        $classOptions = $this->options->class;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);

            $cbo = $metrics->get(self::METRIC_CBO);
            if ($cbo === null) {
                continue;
            }

            $cboValue = (int) $cbo;
            $violation = $this->checkCbo($cboValue, $classInfo, $metrics, $classOptions, RuleLevel::Class_);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * @return list<Violation>
     */
    private function analyzeNamespaceLevel(AnalysisContext $context): array
    {
        if (!$this->options instanceof CboOptions) {
            return [];
        }
        $namespaceOptions = $this->options->namespace;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Namespace_) as $nsInfo) {
            $metrics = $context->metrics->get($nsInfo->symbolPath);

            // Skip namespaces with too few classes
            $classCount = (int) ($metrics->get('classCount.sum') ?? 0);
            if ($classCount < $namespaceOptions->minClassCount) {
                continue;
            }

            $cbo = $metrics->get(self::METRIC_CBO);
            if ($cbo === null) {
                continue;
            }

            $cboValue = (int) $cbo;
            $violation = $this->checkCbo($cboValue, $nsInfo, $metrics, $namespaceOptions, RuleLevel::Namespace_);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * Checks CBO threshold for a symbol.
     */
    private function checkCbo(
        int $cbo,
        SymbolInfo $symbolInfo,
        MetricBag $metrics,
        ClassCboOptions|NamespaceCboOptions $options,
        RuleLevel $level,
    ): ?Violation {
        $ca = (int) ($metrics->get(self::METRIC_CA) ?? 0);
        $ce = (int) ($metrics->get(self::METRIC_CE) ?? 0);

        $violationCode = self::NAME . ($level === RuleLevel::Namespace_ ? '.namespace' : '.class');

        if ($cbo > $options->error) {
            return new Violation(
                location: new Location($symbolInfo->file, $symbolInfo->line),
                symbolPath: $symbolInfo->symbolPath,
                ruleName: $this->getName(),
                violationCode: $violationCode,
                message: \sprintf(
                    'CBO (Coupling Between Objects) is %d (Ca=%d, Ce=%d), exceeds threshold of %d. Reduce dependencies to lower coupling',
                    $cbo,
                    $ca,
                    $ce,
                    $options->error,
                ),
                severity: Severity::Error,
                metricValue: (float) $cbo,
                level: $level,
            );
        }

        if ($cbo > $options->warning) {
            return new Violation(
                location: new Location($symbolInfo->file, $symbolInfo->line),
                symbolPath: $symbolInfo->symbolPath,
                ruleName: $this->getName(),
                violationCode: $violationCode,
                message: \sprintf(
                    'CBO (Coupling Between Objects) is %d (Ca=%d, Ce=%d), exceeds threshold of %d. Reduce dependencies to lower coupling',
                    $cbo,
                    $ca,
                    $ce,
                    $options->warning,
                ),
                severity: Severity::Warning,
                metricValue: (float) $cbo,
                level: $level,
            );
        }

        return null;
    }
}
