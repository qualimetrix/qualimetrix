<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition;

interface ComputedMetricDefinitionCatalogInterface
{
    /** @return list<ComputedMetricDefinition> */
    public function all(): array;

    public function find(string $name): ?ComputedMetricDefinition;
}
