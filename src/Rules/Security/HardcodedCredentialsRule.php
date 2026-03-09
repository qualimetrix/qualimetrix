<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Security;

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
        return ['security.hardcodedCredentials.count'];
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
            $count = (int) ($metrics->get('security.hardcodedCredentials.count') ?? 0);

            if ($count === 0) {
                continue;
            }

            $severity = $this->options->getSeverity($count);
            if ($severity === null) {
                continue;
            }

            for ($i = 0; $i < $count; $i++) {
                $line = (int) ($metrics->get("security.hardcodedCredentials.line.{$i}") ?? 1);
                $patternCode = (int) ($metrics->get("security.hardcodedCredentials.pattern.{$i}") ?? 0);
                $message = match ($patternCode) {
                    1 => 'Hardcoded credential in variable assignment',
                    2 => 'Hardcoded credential in array key',
                    3 => 'Hardcoded credential in class constant',
                    4 => 'Hardcoded credential in define() call',
                    5 => 'Hardcoded credential in property default',
                    6 => 'Hardcoded credential in parameter default',
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
