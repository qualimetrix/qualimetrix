<?php

declare(strict_types=1);

namespace Fixture;

/**
 * A function outside any class: without one the `metrics` export never emits a
 * `function` symbol, and the documented `type` enum could lose that value
 * without anything noticing.
 */
function describeMode(int $mode): string
{
    return $mode > 0 ? 'on' : 'off';
}
