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
 * Rule that checks number of parameters per method/function.
 *
 * Too many parameters indicate a method may need a parameter object
 * or the method is doing too much.
 */
final class LongParameterListRule extends AbstractRule
{
    public const string NAME = 'code-smell.long-parameter-list';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks number of parameters per method';
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
        return [
            MetricName::CODE_SMELL_PARAMETER_COUNT,
            MetricName::CODE_SMELL_IS_VO_CONSTRUCTOR,
        ];
    }

    /**
     * @return class-string<LongParameterListOptions>
     */
    public static function getOptionsClass(): string
    {
        return LongParameterListOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'long-parameter-list-warning' => 'warning',
            'long-parameter-list-error' => 'error',
            'long-parameter-list-vo-warning' => 'vo-warning',
            'long-parameter-list-vo-error' => 'vo-error',
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof LongParameterListOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ([SymbolType::Method, SymbolType::Function_] as $type) {
            foreach ($context->metrics->all($type) as $symbolInfo) {
                $violation = $this->checkSymbol($symbolInfo, $type, $context);

                if ($violation !== null) {
                    $violations[] = $violation;
                }
            }
        }

        return $violations;
    }

    private function checkSymbol(SymbolInfo $symbolInfo, SymbolType $symbolType, AnalysisContext $context): ?Violation
    {
        /** @var LongParameterListOptions $options */
        $options = $this->options;

        $metrics = $context->metrics->get($symbolInfo->symbolPath);
        $parameterCount = $metrics->get(MetricName::CODE_SMELL_PARAMETER_COUNT);

        if ($parameterCount === null) {
            return null;
        }

        $parameterCountValue = (int) $parameterCount;
        $isVoConstructor = $metrics->get(MetricName::CODE_SMELL_IS_VO_CONSTRUCTOR) === 1;

        // VO constructors use relaxed thresholds since many promoted properties is valid design
        if ($isVoConstructor) {
            $severity = $options->getVoSeverity($parameterCountValue);

            if ($severity === null) {
                return null;
            }

            $threshold = $severity === Severity::Error ? $options->voError : $options->voWarning;

            return new Violation(
                location: new Location($symbolInfo->file, $symbolInfo->line),
                symbolPath: $symbolInfo->symbolPath,
                ruleName: $this->getName(),
                violationCode: self::NAME,
                message: \sprintf('VO constructor has %d promoted parameters, exceeds threshold of %d. Consider splitting the value object', $parameterCountValue, $threshold),
                severity: $severity,
                metricValue: $parameterCountValue,
                recommendation: \sprintf('Parameters: %d (VO threshold: %d) — consider splitting the value object', $parameterCountValue, $threshold),
                threshold: $threshold,
            );
        }

        /** @var LongParameterListOptions $effectiveOptions */
        $effectiveOptions = $this->getEffectiveOptions($context, $options, $symbolInfo->file, $symbolInfo->line ?? 1);
        $severity = $effectiveOptions->getSeverity($parameterCountValue);

        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $effectiveOptions->error : $effectiveOptions->warning;
        $kind = $symbolType === SymbolType::Function_ ? 'Function' : 'Method';

        return new Violation(
            location: new Location($symbolInfo->file, $symbolInfo->line),
            symbolPath: $symbolInfo->symbolPath,
            ruleName: $this->getName(),
            violationCode: self::NAME,
            message: \sprintf('%s has %d parameters, exceeds threshold of %d. Consider introducing a parameter object', $kind, $parameterCountValue, $threshold),
            severity: $severity,
            metricValue: $parameterCountValue,
            recommendation: \sprintf('Parameters: %d (threshold: %d) — consider introducing a parameter object', $parameterCountValue, $threshold),
            threshold: $threshold,
        );
    }
}
