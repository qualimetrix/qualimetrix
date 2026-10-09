<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Core\Symbol\SymbolLevel;

final class ComputedMetricAnalysis implements
    ComputedMetricConfiguratorInterface,
    ComputedMetricDefinitionCatalogInterface
{
    private ResolvedComputedMetricDefinitions $definitions;

    public function __construct(
        private readonly ComputedMetricsConfigResolver $configResolver,
    ) {
        $this->definitions = new ResolvedComputedMetricDefinitions([]);
    }

    public function resolve(ConfigurationDocument $document): ResolvedComputedMetricDefinitions
    {
        return $this->configResolver->resolveWithSources($document->resolved());
    }

    public function replace(ResolvedComputedMetricDefinitions $definitions): void
    {
        $this->definitions = $definitions;
    }

    public function refuseFormula(ComputedMetricDefinition $definition, SymbolLevel $level, string $summary): ConfigurationRefusal
    {
        return $this->definitions->refuseFormula($definition, $level, $summary);
    }

    public function all(): array
    {
        return $this->definitions->all();
    }

    public function find(string $name): ?ComputedMetricDefinition
    {
        return $this->definitions->find($name);
    }
}
