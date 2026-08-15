<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Profiler\Contract;

/** Neutral write-only instrumentation vocabulary. */
interface ProfilerInterface
{
    public function start(string $name, ?string $category = null): void;
    public function stop(string $name): void;
}
