<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\CodeSmell;

use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;

/**
 * Detects unused private methods, properties, and constants.
 *
 * Private members that are declared but never referenced within the same class
 * are dead code and should be removed.
 *
 * Limitations:
 * - Dynamic access ($this->$name) is not detected
 * - Callable syntax [$this, 'method'] is not detected
 * - Traits are not analyzed
 * - If __get/__set exist, private properties are not flagged
 * - If __call/__callStatic exist, private methods are not flagged
 */
final class UnusedPrivateRule extends AbstractRule
{
    public const string NAME = 'code-smell.unused-private';

    private const MESSAGES = [
        'method' => 'Unused private method',
        'property' => 'Unused private property',
        'constant' => 'Unused private constant',
    ];

    private const MEMBER_TYPES = ['method', 'property', 'constant'];

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects unused private methods, properties, and constants';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::CodeSmell;
    }

    public function requires(): array
    {
        return [
            'unusedPrivate.total',
        ];
    }

    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);
            $total = (int) ($metrics->get('unusedPrivate.total') ?? 0);

            if ($total === 0) {
                continue;
            }

            foreach (self::MEMBER_TYPES as $type) {
                $countMetric = "unusedPrivate.{$type}.count";
                $count = (int) ($metrics->get($countMetric) ?? 0);

                for ($i = 0; $i < $count; $i++) {
                    $line = (int) ($metrics->get("unusedPrivate.{$type}.line.{$i}") ?? 1);

                    $violations[] = new Violation(
                        location: new Location($classInfo->file, $line),
                        symbolPath: $classInfo->symbolPath,
                        ruleName: $this->getName(),
                        violationCode: $this->getName(),
                        message: self::MESSAGES[$type],
                        severity: Severity::Warning,
                        metricValue: $total,
                    );
                }
            }
        }

        return $violations;
    }

    public static function getOptionsClass(): string
    {
        return UnusedPrivateOptions::class;
    }
}
