<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Security;

use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;

/**
 * Base class for security pattern rules.
 *
 * Provides common functionality for analyzing security pattern metrics
 * from SecurityPatternCollector.
 */
abstract class AbstractSecurityPatternRule extends AbstractRule
{
    /** @var array<int, string> Maps superglobal indices (from MetricBag) back to names */
    private const array SUPERGLOBAL_NAMES = [
        0 => '_GET',
        1 => '_POST',
        2 => '_REQUEST',
        3 => '_COOKIE',
    ];
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
     * @return list<string>
     */
    public function requires(): array
    {
        $type = $this->getPatternType();

        return [
            "security.{$type}.count",
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

        $superglobalNames = self::SUPERGLOBAL_NAMES;

        $violations = [];
        $type = $this->getPatternType();

        foreach ($context->metrics->all(SymbolType::File) as $fileInfo) {
            $metrics = $context->metrics->get($fileInfo->symbolPath);
            $count = (int) ($metrics->get("security.{$type}.count") ?? 0);

            if ($count === 0) {
                continue;
            }

            for ($i = 0; $i < $count; $i++) {
                $line = (int) ($metrics->get("security.{$type}.line.{$i}") ?? 1);
                $superglobalIndex = (int) ($metrics->get("security.{$type}.superglobal.{$i}") ?? -1);
                $superglobalName = $superglobalNames[$superglobalIndex] ?? null;

                $message = $superglobalName !== null
                    ? \sprintf('%s ($%s)', $this->getMessageTemplate(), $superglobalName)
                    : $this->getMessageTemplate();

                $violations[] = new Violation(
                    location: new Location($fileInfo->file, $line),
                    symbolPath: $fileInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: $this->getName(),
                    message: $message,
                    severity: $this->getSeverity(),
                    metricValue: 1.0,
                );
            }
        }

        return $violations;
    }
}
