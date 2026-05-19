<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\AbstractRule;

/**
 * Detects identical sub-expressions that indicate copy-paste errors or logic bugs.
 *
 * Checks for:
 * - Identical operands in binary operations ($a === $a, $a - $a)
 * - Duplicate conditions in if/elseif chains
 * - Identical ternary branches ($cond ? $x : $x)
 * - Duplicate match arm conditions
 * - Duplicate switch case values
 */
final class IdenticalSubExpressionRule extends AbstractRule
{
    public const string NAME = 'code-smell.identical-subexpression';

    /**
     * Finding types with corresponding violation messages.
     * Keys must match the types used by IdenticalSubExpressionCollector.
     *
     * @var array<string, string>
     */
    private const FINDING_TYPES = [
        'identical_operands' => 'Identical sub-expressions on both sides of operator',
        'duplicate_condition' => 'Duplicate condition in if/elseif chain',
        'identical_ternary' => 'Identical expressions in both branches of ternary operator',
        'duplicate_match_arm' => 'Duplicate condition in match expression',
        'duplicate_switch_case' => 'Duplicate case value in switch statement',
    ];

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects identical sub-expressions indicating copy-paste errors or logic bugs';
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
        $requires = [];

        foreach (array_keys(self::FINDING_TYPES) as $type) {
            $requires[] = "identicalSubExpression.{$type}";
        }

        return $requires;
    }

    /**
     * @return class-string<IdenticalSubExpressionOptions>
     */
    public static function getOptionsClass(): string
    {
        return IdenticalSubExpressionOptions::class;
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::File) as $fileInfo) {
            $metrics = $context->metrics->get($fileInfo->symbolPath);

            foreach (self::FINDING_TYPES as $type => $message) {
                foreach ($metrics->entries("identicalSubExpression.{$type}") as $entry) {
                    $line = (int) $entry['line'];

                    $violations[] = new Violation(
                        location: new Location($fileInfo->file, $line, precise: true),
                        symbolPath: $fileInfo->symbolPath,
                        ruleName: $this->getName(),
                        violationCode: self::NAME,
                        message: $message,
                        severity: Severity::Warning,
                        metricValue: 1.0,
                        recommendation: 'This looks like a copy-paste error. Verify the intended logic.',
                    );
                }
            }
        }

        return $violations;
    }
}
