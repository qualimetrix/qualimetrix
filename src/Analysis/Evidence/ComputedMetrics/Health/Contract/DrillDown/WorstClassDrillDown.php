<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\DrillDown;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\OffenderNamespaceSelection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;

final readonly class WorstClassDrillDown
{
    /**
     * @param list<WorstOffender> $offenders
     *
     * @return list<WorstOffender>
     */
    public function buildWorstClasses(array $offenders, OffenderNamespaceSelection $selection): array
    {
        return array_values(array_filter($offenders, static fn(WorstOffender $offender): bool => $selection->matches($offender->symbolPath)));
    }
}
