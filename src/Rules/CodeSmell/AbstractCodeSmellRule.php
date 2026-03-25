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
     *
     * Subclasses may override {@see buildMessage()} instead to access the entry's extra data.
     */
    abstract protected function getMessageTemplate(): string;

    /**
     * Returns the actionable recommendation for this smell.
     *
     * While message describes what is wrong, recommendation tells the user what to do.
     * Subclasses should override to provide a specific recommendation.
     */
    protected function getRecommendation(): ?string
    {
        return null;
    }

    /**
     * Builds the violation message for a single entry.
     *
     * Subclasses may override this to incorporate extra data (e.g. parameter name) from the entry.
     *
     * @param array<string, mixed> $entry
     */
    protected function buildMessage(array $entry): string
    {
        return $this->getMessageTemplate();
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        $type = $this->getSmellType();

        return [
            "codeSmell.{$type}",
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
            $entries = $metrics->entries("codeSmell.{$type}");

            foreach ($entries as $entry) {
                $line = (int) $entry['line'];

                $violations[] = new Violation(
                    location: new Location($fileInfo->file, $line, precise: true),
                    symbolPath: $fileInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: $this->getName(),
                    message: $this->buildMessage($entry),
                    severity: $this->getSeverity(),
                    metricValue: 1.0,
                    recommendation: $this->getRecommendation(),
                );
            }
        }

        return $violations;
    }
}
