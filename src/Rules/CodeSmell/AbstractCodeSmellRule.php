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
 * Base class for code smell rules.
 *
 * Provides common functionality for analyzing code smell metrics
 * from CodeSmellCollector.
 */
abstract class AbstractCodeSmellRule extends AbstractRule
{
    public function getCategory(): RuleCategory
    {
        return RuleCategory::CodeSmell;
    }

    /**
     * Returns the code smell type this rule checks.
     */
    abstract protected function getSmellType(): string;

    /**
     * Returns severity for this smell.
     */
    abstract protected function getSeverity(): Severity;

    /**
     * Returns the violation message describing a single occurrence.
     */
    abstract protected function getMessageTemplate(): string;

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        $type = $this->getSmellType();

        return [
            "codeSmell.{$type}.count",
        ];
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
        $type = $this->getSmellType();

        foreach ($context->metrics->all(SymbolType::File) as $fileInfo) {
            $metrics = $context->metrics->get($fileInfo->symbolPath);
            $count = (int) ($metrics->get("codeSmell.{$type}.count") ?? 0);

            if ($count === 0) {
                continue;
            }

            // Create one violation per occurrence with correct line
            for ($i = 0; $i < $count; $i++) {
                $line = (int) ($metrics->get("codeSmell.{$type}.line.{$i}") ?? 1);

                $violations[] = new Violation(
                    location: new Location($fileInfo->file, $line),
                    symbolPath: $fileInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: $this->getName(),
                    message: $this->getMessageTemplate(),
                    severity: $this->getSeverity(),
                    metricValue: 1.0,
                );
            }
        }

        return $violations;
    }
}
