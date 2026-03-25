<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\AbstractRule;

/**
 * Rule that checks number of constructor parameters (dependencies).
 *
 * Too many constructor parameters indicate a class has too many dependencies
 * and likely violates the Single Responsibility Principle.
 */
final class ConstructorOverinjectionRule extends AbstractRule
{
    public const string NAME = 'code-smell.constructor-overinjection';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks number of constructor parameters (dependencies)';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::CodeSmell;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::CODE_SMELL_PARAMETER_COUNT];
    }

    /**
     * @return class-string<ConstructorOverinjectionOptions>
     */
    public static function getOptionsClass(): string
    {
        return ConstructorOverinjectionOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'constructor-overinjection-warning' => 'warning',
            'constructor-overinjection-error' => 'error',
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof ConstructorOverinjectionOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Method) as $symbolInfo) {
            $violation = $this->checkSymbol($symbolInfo, $context);

            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    private function checkSymbol(SymbolInfo $symbolInfo, AnalysisContext $context): ?Violation
    {
        /** @var ConstructorOverinjectionOptions $options */
        $options = $this->options;

        // Only check constructors
        if ($symbolInfo->symbolPath->member !== '__construct') {
            return null;
        }

        // Skip global functions (no class context)
        if ($symbolInfo->symbolPath->type === null) {
            return null;
        }

        $metrics = $context->metrics->get($symbolInfo->symbolPath);
        $parameterCount = $metrics->get(MetricName::CODE_SMELL_PARAMETER_COUNT);

        if ($parameterCount === null) {
            return null;
        }

        $parameterCountValue = (int) $parameterCount;
        $severity = $options->getSeverity($parameterCountValue);

        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $options->error : $options->warning;
        $className = $symbolInfo->symbolPath->type;

        return new Violation(
            location: new Location($symbolInfo->file, $symbolInfo->line),
            symbolPath: $symbolInfo->symbolPath,
            ruleName: $this->getName(),
            violationCode: self::NAME,
            message: \sprintf(
                'Constructor of %s has %d parameters (threshold %d). Consider using a parameter object or splitting responsibilities',
                $className,
                $parameterCountValue,
                $threshold,
            ),
            severity: $severity,
            metricValue: $parameterCountValue,
            recommendation: \sprintf('Constructor parameters: %d (threshold: %d) — consider splitting responsibilities', $parameterCountValue, $threshold),
            threshold: $threshold,
        );
    }
}
