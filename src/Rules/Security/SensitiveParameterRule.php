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
 * Detects parameters with sensitive names missing #[\SensitiveParameter].
 *
 * Parameters named password, secret, apiKey, etc. should use the
 * #[\SensitiveParameter] attribute to prevent credential leakage in stack traces.
 */
final class SensitiveParameterRule extends AbstractRule
{
    public const string NAME = 'security.sensitive-parameter';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects sensitive parameters missing #[\\SensitiveParameter] attribute';
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
        return ['security.sensitiveParameter.count'];
    }

    /**
     * @return class-string<SensitiveParameterOptions>
     */
    public static function getOptionsClass(): string
    {
        return SensitiveParameterOptions::class;
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof SensitiveParameterOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::File) as $fileInfo) {
            $metrics = $context->metrics->get($fileInfo->symbolPath);
            $count = (int) ($metrics->get('security.sensitiveParameter.count') ?? 0);

            if ($count === 0) {
                continue;
            }

            $severity = $this->options->getSeverity($count);
            if ($severity === null) {
                continue;
            }

            for ($i = 0; $i < $count; $i++) {
                $line = (int) ($metrics->get("security.sensitiveParameter.line.{$i}") ?? 1);

                $violations[] = new Violation(
                    location: new Location($fileInfo->file, $line),
                    symbolPath: $fileInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME,
                    message: 'Sensitive parameter missing #[\\SensitiveParameter] attribute — add it to prevent credential leakage in stack traces',
                    severity: $severity,
                    metricValue: 1.0,
                );
            }
        }

        return $violations;
    }
}
