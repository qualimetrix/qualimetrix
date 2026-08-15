<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Progress;

use Qualimetrix\Analysis\Run\Contract\Progress\ProgressReporterInterface;

final class SwitchableProgressReporter implements ProgressReporterInterface
{
    private ?ProgressReporterInterface $delegate = null;
    public function enable(ProgressReporterInterface $reporter): void
    {
        $this->delegate = $reporter;
    }
    public function reset(): void
    {
        $this->delegate = null;
    }
    public function start(int $total): void
    {
        $this->delegate?->start($total);
    }
    public function advance(int $step = 1): void
    {
        $this->delegate?->advance($step);
    }
    public function setMessage(string $message): void
    {
        $this->delegate?->setMessage($message);
    }
    public function finish(): void
    {
        $this->delegate?->finish();
    }
}
