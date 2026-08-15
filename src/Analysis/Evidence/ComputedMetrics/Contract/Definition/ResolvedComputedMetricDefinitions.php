<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition;

/** Immutable computed-metric definitions resolved for one configuration run. */
final readonly class ResolvedComputedMetricDefinitions implements ComputedMetricDefinitionCatalogInterface
{
    /** @param list<ComputedMetricDefinition> $definitions */
    public function __construct(
        private array $definitions,
    ) {}

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
