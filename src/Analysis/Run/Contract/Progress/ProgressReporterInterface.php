<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Progress;

interface ProgressReporterInterface
{
    public function start(int $total): void;
    public function advance(int $step = 1): void;
    public function setMessage(string $message): void;
    public function finish(): void;
}
