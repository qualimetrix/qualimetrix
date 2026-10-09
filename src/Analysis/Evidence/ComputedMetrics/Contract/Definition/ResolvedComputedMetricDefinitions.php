<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricAuthorship;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricsSection;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Immutable computed-metric definitions resolved for one configuration run. */
final readonly class ResolvedComputedMetricDefinitions implements ComputedMetricDefinitionCatalogInterface
{
    /** @param list<ComputedMetricDefinition> $definitions */
    public function __construct(
        private array $definitions,
        private ?ComputedMetricAuthorship $authorship = null,
    ) {}

    public function refuseFormula(ComputedMetricDefinition $definition, SymbolLevel $level, string $summary): ConfigurationRefusal
    {
        return $this->authorship?->refuseFormula($definition, $level->value, $summary)
            ?? ConfigurationRefusal::atResolvedKey(ComputedMetricsSection::position($definition->name), $summary);
    }

    public function all(): array
    {
        return $this->definitions;
    }

    public function find(string $name): ?ComputedMetricDefinition
    {
        foreach ($this->definitions as $definition) {
            if ($definition->name === $name) {
                return $definition;
            }
        }

        return null;
    }
}
