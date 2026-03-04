<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Console\Progress;

use AiMessDetector\Core\Progress\NullProgressReporter;
use AiMessDetector\Core\Progress\ProgressReporter;

/**
 * Holds the current progress reporter instance.
 *
 * Used for runtime configuration of progress reporting.
 * Similar to LoggerHolder pattern - allows changing progress reporter
 * at runtime after DI container is compiled.
 */
final class ProgressReporterHolder
{
    private ProgressReporter $reporter;

    public function __construct()
    {
        $this->reporter = new NullProgressReporter();
    }

    public function getReporter(): ProgressReporter
    {
        return $this->reporter;
    }

    public function setReporter(ProgressReporter $reporter): void
    {
        $this->reporter = $reporter;
    }
}
