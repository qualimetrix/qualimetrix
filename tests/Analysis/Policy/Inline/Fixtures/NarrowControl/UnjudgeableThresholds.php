<?php

declare(strict_types=1);

namespace Fixtures\NarrowControl;

/**
 * The two refusals a threshold can meet before the sweep is ever asked.
 *
 * Neither is a branch `--sweep` executes: both are decided by reading the
 * configuration and the channel catalogue. They are here for the vocabulary the
 * population floor demands, not for the discrimination it exists to buy — see
 * {@see \QmxDirectiveAudit\HeterogeneityFloor} on which axis carries which.
 */
final class UnjudgeableThresholds
{
    /**
     * @qmx-threshold narrow-control.no-such-channel warning=1 error=2 -- already-refused: no
     *                producer owns this channel, and the annotation rule says so.
     * @qmx-threshold complexity.cognitive warning=50 error=80 -- producer-disabled: switched off
     *                in this fixture's own qmx.yaml.
     */
    public function unjudgeable(): void {}
}
