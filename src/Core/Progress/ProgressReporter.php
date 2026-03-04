<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Progress;

/**
 * Reports progress of analysis operations.
 *
 * Provides a unified interface for tracking analysis progress,
 * with implementations that can display console progress bars
 * or no-op for quiet/non-TTY environments.
 */
interface ProgressReporter
{
    /**
     * Starts progress tracking.
     *
     * @param int $total Total number of items to process
     */
    public function start(int $total): void;

    /**
     * Advances progress by the specified number of steps.
     *
     * @param int $step Number of steps to advance (default: 1)
     */
    public function advance(int $step = 1): void;

    /**
     * Sets a message describing the current operation.
     *
     * @param string $message Message to display
     */
    public function setMessage(string $message): void;

    /**
     * Finishes progress tracking and cleans up display.
     */
    public function finish(): void;
}
