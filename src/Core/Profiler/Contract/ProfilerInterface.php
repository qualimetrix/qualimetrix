<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Profiler\Contract;

/** Neutral write-only instrumentation vocabulary. */
interface ProfilerInterface
{
    /** @param array<string, scalar> $attributes */
    public function start(string $name, ?string $category = null, array $attributes = []): void;
    public function stop(string $name): void;
}
