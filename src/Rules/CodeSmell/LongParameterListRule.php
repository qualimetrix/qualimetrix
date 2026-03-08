<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\CodeSmell;

use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;

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
        return ['parameterCount'];
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
                $violation = $this->checkSymbol($symbolInfo, $context);

                if ($violation !== null) {
                    $violations[] = $violation;
                }
            }
        }

        return $violations;
    }

    private function checkSymbol(SymbolInfo $symbolInfo, AnalysisContext $context): ?Violation
    {
        /** @var LongParameterListOptions $options */
        $options = $this->options;

        $metrics = $context->metrics->get($symbolInfo->symbolPath);
        $parameterCount = $metrics->get('parameterCount');

        if ($parameterCount === null) {
            return null;
        }

        $parameterCountValue = (int) $parameterCount;
        $severity = $options->getSeverity($parameterCountValue);

        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $options->error : $options->warning;

        return new Violation(
            location: new Location($symbolInfo->file, $symbolInfo->line),
            symbolPath: $symbolInfo->symbolPath,
            ruleName: $this->getName(),
            violationCode: self::NAME,
            message: \sprintf('Method has %d parameters, exceeds threshold of %d. Consider introducing a parameter object', $parameterCountValue, $threshold),
            severity: $severity,
            metricValue: $parameterCountValue,
        );
    }
}
