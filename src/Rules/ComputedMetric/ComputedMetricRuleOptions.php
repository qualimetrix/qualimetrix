<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\ComputedMetric;

use AiMessDetector\Core\ComputedMetric\ComputedMetricDefinition;
use AiMessDetector\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

final readonly class ComputedMetricRuleOptions implements RuleOptionsInterface
{
    /**
     * @param list<ComputedMetricDefinition> $definitions
     */
    public function __construct(
        private bool $enabled = true,
        private array $definitions = [],
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // Definitions come from the holder (set by RuntimeConfigurator)
        // NOT from the config array — config is handled by ComputedMetricsConfigResolver
        $definitions = ComputedMetricDefinitionHolder::getDefinitions();

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            definitions: $definitions,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return list<ComputedMetricDefinition>
     */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    /**
     * Returns null — severity is determined per-definition in the rule.
     */
    public function getSeverity(int|float $value): ?Severity
    {
        return null;
    }
}
