<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Complexity;

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
 * Hierarchical rule that checks complexity at method and class levels.
 *
 * - Method level: checks individual method CCN
 * - Class level: checks maximum CCN among class methods
 */
final class ComplexityRule extends AbstractRule implements HierarchicalRuleInterface
{
    public const string NAME = 'complexity';
    private const string METRIC_CCN = 'ccn';

    public function __construct(
        RuleOptionsInterface $options,
    ) {
        if (!$options instanceof ComplexityOptions) {
            throw new InvalidArgumentException(
                \sprintf('Expected %s, got %s', ComplexityOptions::class, $options::class),
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
        return 'Checks cyclomatic complexity at method and class levels';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Complexity;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [self::METRIC_CCN];
    }

    /**
     * @return list<RuleLevel>
     */
    public function getSupportedLevels(): array
    {
        return [RuleLevel::Method, RuleLevel::Class_];
    }

    /**
     * Analyzes at a specific level.
     *
     * @return list<Violation>
     */
    public function analyzeLevel(RuleLevel $level, AnalysisContext $context): array
    {
        if (!$this->options instanceof ComplexityOptions) {
            return [];
        }

        $levelOptions = $this->options->forLevel($level);
        if (!$levelOptions->isEnabled()) {
            return [];
        }

        return match ($level) {
            RuleLevel::Method => $this->analyzeMethodLevel($context),
            RuleLevel::Class_ => $this->analyzeClassLevel($context),
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
            if ($this->options instanceof ComplexityOptions && $this->options->isLevelEnabled($level)) {
                $violations = [...$violations, ...$this->analyzeLevel($level, $context)];
            }
        }

        return $violations;
    }

    /**
     * @return class-string<ComplexityOptions>
     */
    public static function getOptionsClass(): string
    {
        return ComplexityOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            // Legacy aliases for backward compatibility (maps to method level)
            'cc-warning' => 'method.warning',
            'cc-error' => 'method.error',
            // Class-level aliases
            'cc-class-warning' => 'class.max_warning',
            'cc-class-error' => 'class.max_error',
        ];
    }

    /**
     * @return list<Violation>
     */
    private function analyzeMethodLevel(AnalysisContext $context): array
    {
        if (!$this->options instanceof ComplexityOptions) {
            return [];
        }
        $methodOptions = $this->options->method;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Method) as $methodInfo) {
            $metrics = $context->metrics->get($methodInfo->symbolPath);
            $ccn = $metrics->get(self::METRIC_CCN);

            if ($ccn === null) {
                continue;
            }

            $ccnValue = (int) $ccn;
            $severity = $methodOptions->getSeverity($ccnValue);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error ? $methodOptions->error : $methodOptions->warning;

                $violations[] = new Violation(
                    location: new Location($methodInfo->file, $methodInfo->line),
                    symbolPath: $methodInfo->symbolPath,
                    ruleName: $this->getName(),
                    message: \sprintf('Cyclomatic complexity is %d, exceeds threshold of %d. Consider extracting methods or simplifying conditions', $ccnValue, $threshold),
                    severity: $severity,
                    metricValue: $ccnValue,
                    level: RuleLevel::Method,
                );
            }
        }

        return $violations;
    }

    /**
     * @return list<Violation>
     */
    private function analyzeClassLevel(AnalysisContext $context): array
    {
        if (!$this->options instanceof ComplexityOptions) {
            return [];
        }
        $classOptions = $this->options->class;

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);
            $maxCcn = $metrics->get('ccn.max');

            if ($maxCcn === null) {
                continue;
            }

            $maxCcnValue = (int) $maxCcn;
            $severity = $classOptions->getSeverity($maxCcnValue);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error ? $classOptions->maxError : $classOptions->maxWarning;

                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
                    ruleName: $this->getName(),
                    message: \sprintf('Maximum method cyclomatic complexity is %d, exceeds threshold of %d. Refactor the most complex methods', $maxCcnValue, $threshold),
                    severity: $severity,
                    metricValue: $maxCcnValue,
                    level: RuleLevel::Class_,
                );
            }
        }

        return $violations;
    }
}
