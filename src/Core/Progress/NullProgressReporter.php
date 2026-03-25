<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Progress;

/**
 * No-op progress reporter.
 *
 * Used when progress reporting is disabled:
 * - Quiet mode
 * - Non-TTY output (CI, pipes)
 * - Explicit --no-progress flag
 */
final class NullProgressReporter implements ProgressReporter
{
    public function start(int $total): void
    {
        // No-op
    }

    public function advance(int $step = 1): void
    {
        // No-op
    }

    public function setMessage(string $message): void
    {
        // No-op
    }

    public function finish(): void
    {
        // No-op
    }
}
