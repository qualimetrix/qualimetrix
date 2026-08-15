<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Profiler\Contract;

interface ProfileSessionControlInterface
{
    public function enable(): void;
    public function disable(): void;
}
