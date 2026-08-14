<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricContributionReader;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;

final class ComputedMetricAnalysis implements
    ComputedMetricConfiguratorInterface,
    ComputedMetricDefinitionCatalogInterface
{
    /** @var list<ComputedMetricDefinition> */
    private array $definitions = [];

    public function __construct(
        private readonly ComputedMetricsConfigResolver $configResolver,
        private readonly ComputedMetricContributionReader $contributionReader,
    ) {}

    public function configure(ConfigurationDocument $document): void
    {
        $this->definitions = [];

        $contributions = $this->contributionReader->read($document);
        $this->publishDefinitions($this->configResolver->resolve($contributions['computedMetrics'], $contributions['excludeHealth']));
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

    /** @param list<ComputedMetricDefinition> $definitions */
    private function publishDefinitions(array $definitions): void
    {
        $this->clearDefinitions();
        $this->definitions = $definitions;
    }

    private function clearDefinitions(): void
    {
        $this->definitions = [];
    }
}
