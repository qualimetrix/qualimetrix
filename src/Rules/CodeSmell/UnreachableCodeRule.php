<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\AbstractRule;

/**
 * Rule that detects unreachable code after terminal statements.
 *
 * Statements after return, throw, exit/die, continue, or break
 * are unreachable and should be removed.
 */
final class UnreachableCodeRule extends AbstractRule
{
    public const string NAME = 'code-smell.unreachable-code';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects unreachable code after terminal statements';
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
        return [MetricName::CODE_SMELL_UNREACHABLE_CODE];
    }

    /**
     * @return class-string<UnreachableCodeOptions>
     */
    public static function getOptionsClass(): string
    {
        return UnreachableCodeOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'unreachable-code-warning' => 'warning',
            'unreachable-code-error' => 'error',
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof UnreachableCodeOptions || !$this->options->isEnabled()) {
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
        /** @var UnreachableCodeOptions $options */
        $options = $this->options;

        $metrics = $context->metrics->get($symbolInfo->symbolPath);
        $unreachableCount = $metrics->get(MetricName::CODE_SMELL_UNREACHABLE_CODE);

        if ($unreachableCount === null) {
            return null;
        }

        $unreachableCountValue = (int) $unreachableCount;
        $severity = $this->getEffectiveSeverity($context, $options, $symbolInfo->file, $symbolInfo->line ?? 1, $unreachableCountValue);

        if ($severity === null) {
            return null;
        }

        $firstLine = $metrics->get(MetricName::CODE_SMELL_UNREACHABLE_CODE_FIRST_LINE);
        $line = $firstLine !== null ? (int) $firstLine : $symbolInfo->line;

        return new Violation(
            location: new Location($symbolInfo->file, $line, precise: true),
            symbolPath: $symbolInfo->symbolPath,
            ruleName: $this->getName(),
            violationCode: self::NAME,
            message: \sprintf(
                'Found %d unreachable statement(s) after terminal statement (return/throw/exit/break/continue). Dead code should be removed',
                $unreachableCountValue,
            ),
            severity: $severity,
            metricValue: $unreachableCountValue,
            recommendation: 'Remove dead code after the terminal statement.',
        );
    }
}
