<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use RuntimeException;
use Throwable;

final readonly class RuntimeLimitsController
{
    private string $startupLimit;
    public function __construct()
    {
        $this->startupLimit = (string) \ini_get('memory_limit');
    }
    public function reset(): void
    {
        $this->setMemoryLimit($this->startupLimit, 'Cannot restore process-start memory_limit.');
    }
    public function apply(RuntimeLimits $limits): void
    {
        if ($limits->memoryLimit !== null) {
            $this->setMemoryLimit($limits->memoryLimit, 'Cannot set requested memory_limit.');
        }
    }

    private function setMemoryLimit(string $limit, string $failureMessage): void
    {
        set_error_handler(static fn(): bool => true);
        try {
            try {
                $previousLimit = ini_set('memory_limit', $limit);
            } catch (Throwable $exception) {
                throw new RuntimeException($failureMessage, 0, $exception);
            }
        } finally {
            restore_error_handler();
        }

        if ($previousLimit === false) {
            throw new RuntimeException($failureMessage);
        }
    }
}
