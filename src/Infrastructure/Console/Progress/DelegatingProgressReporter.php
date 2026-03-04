<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Console\Progress;

use AiMessDetector\Core\Progress\ProgressReporter;

/**
 * Delegates all progress reporting calls to the current reporter in ProgressReporterHolder.
 *
 * This allows runtime configuration of progress reporting after DI container is compiled.
 * Similar to DelegatingLogger pattern.
 */
final class DelegatingProgressReporter implements ProgressReporter
{
    public function __construct(
        private readonly ProgressReporterHolder $holder,
    ) {}

    public function start(int $total): void
    {
        $this->holder->getReporter()->start($total);
    }

    public function advance(int $step = 1): void
    {
        $this->holder->getReporter()->advance($step);
    }

    public function setMessage(string $message): void
    {
        $this->holder->getReporter()->setMessage($message);
    }

    public function finish(): void
    {
        $this->holder->getReporter()->finish();
    }
}
