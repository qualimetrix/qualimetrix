<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender;

use LogicException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;

/** Immutable definitions and thresholds captured for one report. */
final readonly class OffenderThresholds
{
    /** @var array<string, array{definition: ComputedMetricDefinition, pair: array{float|null, float|null}}> */
    private array $snapshot;

    /** @param array<string, ComputedMetricDefinition> $definitions */
    private function __construct(array $definitions)
    {
        $snapshot = [];
        foreach (HealthDimension::all() as $dimension) {
            $definition = $definitions[$dimension->value] ?? null;
            if ($definition !== null) {
                $snapshot[$dimension->value] = ['definition' => $definition, 'pair' => [$definition->warningThreshold, $definition->errorThreshold]];
            }
        }
        $this->snapshot = $snapshot;
    }

    public static function fromCatalog(ComputedMetricDefinitionCatalogInterface $catalog): self
    {
        $definitions = [];
        foreach ($catalog->all() as $definition) {
            $definitions[$definition->name] = $definition;
        }

        return new self($definitions);
    }

    public function definition(HealthDimension $dimension): ?ComputedMetricDefinition
    {
        return $this->snapshot[$dimension->value]['definition'] ?? null;
    }

    /** @return array{float, float} */
    public function pair(HealthDimension $dimension): array
    {
        $pair = $this->snapshot[$dimension->value]['pair']
            ?? throw new LogicException('Measured health score requires configured thresholds: ' . $dimension->value);
        if ($pair[0] === null || $pair[1] === null) {
            throw new LogicException('Measured health score requires configured thresholds: ' . $dimension->value);
        }

        return $pair;
    }
}
