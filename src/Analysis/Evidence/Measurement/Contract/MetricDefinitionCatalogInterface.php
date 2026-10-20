<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

interface MetricDefinitionCatalogInterface
{
    /** @return list<MetricDefinition> */
    public function all(): array;
}
