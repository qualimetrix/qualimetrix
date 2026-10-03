<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

/** A collector whose metrics require the run's captured source bytes. */
interface SourceMeasuringCollectorInterface
{
    public function measureSource(string $source): void;
}
