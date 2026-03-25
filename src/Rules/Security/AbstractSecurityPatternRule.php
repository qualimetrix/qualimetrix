<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Security;

use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\AbstractRule;

/**
 * Base class for security pattern rules.
 *
 * Provides common functionality for analyzing security pattern metrics
 * from SecurityPatternCollector.
 */
abstract class AbstractSecurityPatternRule extends AbstractRule
{
    public function getCategory(): RuleCategory
    {
        return RuleCategory::Security;
    }

    /**
     * Returns the security pattern type this rule checks.
     */
    abstract protected function getPatternType(): string;

    /**
     * Returns severity for this pattern.
     */
    abstract protected function getSeverity(): Severity;

    /**
     * Returns the violation message template.
     */
    abstract protected function getMessageTemplate(): string;

    /**
     * Returns the actionable recommendation for this security pattern.
     *
     * While message describes what is wrong, recommendation tells the user what to do.
     * Subclasses should override to provide a specific recommendation.
     */
    protected function getRecommendation(): ?string
    {
        return null;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        $type = $this->getPatternType();

        return [
            "security.{$type}",
        ];
    }

    /**
     * @return class-string<SecurityPatternOptions>
     */
    public static function getOptionsClass(): string
    {
        return SecurityPatternOptions::class;
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof SecurityPatternOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];
        $type = $this->getPatternType();

        foreach ($context->metrics->all(SymbolType::File) as $fileInfo) {
            $metrics = $context->metrics->get($fileInfo->symbolPath);
            $entries = $metrics->entries("security.{$type}");

            if ($entries === []) {
                continue;
            }

            foreach ($entries as $entry) {
                $line = (int) $entry['line'];
                $superglobal = (string) ($entry['superglobal'] ?? '');

                $message = $superglobal !== ''
                    ? \sprintf('%s ($%s)', $this->getMessageTemplate(), $superglobal)
                    : $this->getMessageTemplate();

                $violations[] = new Violation(
                    location: new Location($fileInfo->file, $line, precise: true),
                    symbolPath: $fileInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: $this->getName(),
                    message: $message,
                    severity: $this->getSeverity(),
                    metricValue: 1.0,
                    recommendation: $this->getRecommendation(),
                );
            }
        }

        return $violations;
    }
}
