<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Security;

use AiMessDetector\Core\Metric\MetricName;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;

/**
 * Detects hardcoded credentials in PHP code.
 *
 * Checks for string literal values assigned to variables, properties, constants,
 * array keys, and parameters with credential-related names.
 */
final class HardcodedCredentialsRule extends AbstractRule
{
    public const string NAME = 'security.hardcoded-credentials';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects hardcoded credentials in code';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Security;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::SECURITY_HARDCODED_CREDENTIALS];
    }

    /**
     * @return class-string<HardcodedCredentialsOptions>
     */
    public static function getOptionsClass(): string
    {
        return HardcodedCredentialsOptions::class;
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof HardcodedCredentialsOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::File) as $fileInfo) {
            $metrics = $context->metrics->get($fileInfo->symbolPath);
            $entries = $metrics->entries(MetricName::SECURITY_HARDCODED_CREDENTIALS);

            if ($entries === []) {
                continue;
            }

            $severity = $this->options->getSeverity(\count($entries));
            if ($severity === null) {
                continue;
            }

            foreach ($entries as $entry) {
                $line = (int) $entry['line'];
                $pattern = (string) $entry['pattern'];
                $message = match ($pattern) {
                    'variable' => 'Hardcoded credential in variable assignment',
                    'array_key' => 'Hardcoded credential in array key',
                    'class_const' => 'Hardcoded credential in class constant',
                    'define' => 'Hardcoded credential in define() call',
                    'property' => 'Hardcoded credential in property default',
                    'parameter' => 'Hardcoded credential in parameter default',
                    'enum_case' => 'Hardcoded credential in enum case',
                    default => 'Hardcoded credential found',
                };
                $message .= ' — use environment variables or a secrets manager';

                $violations[] = new Violation(
                    location: new Location($fileInfo->file, $line),
                    symbolPath: $fileInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME,
                    message: $message,
                    severity: $severity,
                    metricValue: 1.0,
                );
            }
        }

        return $violations;
    }
}
