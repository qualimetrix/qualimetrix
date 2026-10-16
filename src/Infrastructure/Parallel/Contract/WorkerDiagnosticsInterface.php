<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Contract;

interface WorkerDiagnosticsInterface
{
    public function diagnostic(string $message): void;
}
