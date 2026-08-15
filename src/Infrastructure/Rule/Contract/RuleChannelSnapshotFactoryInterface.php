<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Rule\Contract;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;

/** Creates one immutable rule-channel view for a resolved configuration run. */
interface RuleChannelSnapshotFactoryInterface
{
    public function snapshot(ResolvedComputedMetricDefinitions $definitions): RuleChannelRegistryInterface;
}
