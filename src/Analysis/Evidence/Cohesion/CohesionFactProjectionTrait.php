<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Publishes file-local Cohesion fact rows under exact class declarations. */
trait CohesionFactProjectionTrait
{
    /**
     * @param array<int, LcomClassData|TccLccClassData> $classes
     *
     * @return list<ClassWithMetrics>
     */
    private function classesWithCohesionFacts(RelativePath $file, array $classes, string $factKey): array
    {
        $result = [];
        foreach ($classes as $classData) {
            $bag = new MetricBag();
            foreach (CohesionFactWire::rows($classData) as $row) {
                $bag = $bag->withEntry($factKey, $row);
            }

            $result[] = $this->classWithMetrics(
                SymbolPath::forClass($classData->namespace ?? '', $classData->className),
                $file,
                $classData->startFilePos,
                $classData->line,
                $bag,
            );
        }

        return $result;
    }
}
