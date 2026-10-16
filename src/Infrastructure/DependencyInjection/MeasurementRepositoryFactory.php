<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassKeyScope;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;

final readonly class MeasurementRepositoryFactory implements MetricRepositoryFactoryInterface
{
    public function __construct(
        private MetricDefinitionCatalogInterface $measured,
        private ComputedMetricDefinitionCatalogInterface $computed,
        private MetricRepositoryFactoryInterface $storageFactory,
    ) {}

    public function create(array $definitions = []): MetricRepositoryInterface
    {
        $definitions = [...$this->measured->all(), ...$definitions];
        foreach ($this->computed->all() as $computed) {
            if ($computed->hasLevel(SymbolLevel::Class_)) {
                $definitions[] = new MetricDefinition(name: $computed->name, collectedAt: SymbolLevel::Class_, classKeyScope: ClassKeyScope::Declaration);
            }
        }

        return $this->storageFactory->create($definitions);
    }
}
