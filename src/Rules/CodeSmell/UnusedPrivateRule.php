<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\CodeSmell;

use AiMessDetector\Core\Metric\MetricName;
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

    private const ENTRY_KEYS = [
        MetricName::STRUCTURE_UNUSED_PRIVATE_METHOD => 'Unused private method',
        MetricName::STRUCTURE_UNUSED_PRIVATE_PROPERTY => 'Unused private property',
        MetricName::STRUCTURE_UNUSED_PRIVATE_CONSTANT => 'Unused private constant',
    ];

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
            MetricName::STRUCTURE_UNUSED_PRIVATE_TOTAL,
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
            $total = (int) ($metrics->get(MetricName::STRUCTURE_UNUSED_PRIVATE_TOTAL) ?? 0);

            if ($total === 0) {
                continue;
            }

            foreach (self::ENTRY_KEYS as $entryKey => $label) {
                foreach ($metrics->entries($entryKey) as $entry) {
                    $line = (int) $entry['line'];
                    $name = isset($entry['name']) ? (string) $entry['name'] : null;
                    $message = $name !== null ? \sprintf('%s `%s`', $label, $name) : $label;

                    $violations[] = new Violation(
                        location: new Location($classInfo->file, $line, precise: true),
                        symbolPath: $classInfo->symbolPath,
                        ruleName: $this->getName(),
                        violationCode: $this->getName(),
                        message: $message,
                        severity: Severity::Warning,
                        metricValue: $total,
                        recommendation: 'Remove the unused symbol to reduce dead code.',
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
