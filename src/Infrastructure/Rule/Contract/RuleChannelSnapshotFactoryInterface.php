<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Rule\Contract;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;

/**
 * Builds one channel universe over an explicit, immutable set of resolved
 * computed-metric definitions.
 *
 * Exists for preflight: CLI selector validation has to know the universe of
 * the configuration it is validating, and it runs before any store has
 * accepted a value. Building a second universe over the candidate definitions
 * answers that without mutating anything.
 */
interface RuleChannelSnapshotFactoryInterface
{
    public function snapshot(ResolvedComputedMetricDefinitions $definitions): ChannelUniverseInterface;
}
