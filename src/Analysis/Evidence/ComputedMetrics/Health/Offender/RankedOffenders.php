<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;

final readonly class RankedOffenders
{
    /** @param list<WorstOffender> $namespaces
     * @param list<WorstOffender> $classes
     */
    public function __construct(public array $namespaces, public array $classes) {}
}
