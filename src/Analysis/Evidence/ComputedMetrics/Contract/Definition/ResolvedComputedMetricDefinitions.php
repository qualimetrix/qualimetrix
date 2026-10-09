<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition;

use Closure;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Immutable computed-metric definitions resolved for one configuration run. */
final readonly class ResolvedComputedMetricDefinitions implements ComputedMetricDefinitionCatalogInterface
{
    /**
     * @param list<ComputedMetricDefinition> $definitions
     * @param (Closure(ComputedMetricDefinition, SymbolLevel, string): ConfigurationRefusal)|null $refuseFormula
     */
    public function __construct(
        private array $definitions,
        private ?Closure $refuseFormula = null,
    ) {}

    public function refuseFormula(ComputedMetricDefinition $definition, SymbolLevel $level, string $summary): ConfigurationRefusal
    {
        return $this->refuseFormula !== null
            ? ($this->refuseFormula)($definition, $level, $summary)
            : ConfigurationRefusal::atResolvedKey(RefusedPosition::open(['computed_metrics', $definition->name], $definition->name), $summary);
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
