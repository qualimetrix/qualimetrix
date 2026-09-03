<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Progress;

use Qualimetrix\Analysis\Run\Contract\Progress\ProgressReporterInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleSectionOutput;

/**
 * Console progress bar implementation using Symfony ProgressBar.
 *
 * **The section is given, not made.** The bar used to ask its output whether it
 * was a `ConsoleOutputInterface` and call `section()` on it. That question is
 * about who can *produce* a section, and it has the wrong answer now that the
 * section is built over the error stream: `getErrorOutput()` returns a plain
 * `StreamOutput`, so the old gate would have silently disabled progress
 * altogether. The caller decides whether progress is possible at all
 * ({@see \Qualimetrix\Infrastructure\Console\RuntimeConfigurator}); what
 * reaches here is the section to draw in.
 *
 * Progress is still skipped for a file count below the threshold, because a
 * run that finishes in a moment has nothing to report.
 *
 * Uses null-safe operations to ensure no errors when progress bar is disabled.
 */
final class ConsoleProgressBar implements ProgressReporterInterface
{
    private ?ProgressBar $progressBar = null;

    public function __construct(
        private readonly ConsoleSectionOutput $section,
        private readonly int $minFilesForProgress = 10,
    ) {}

    public function start(int $total): void
    {
        // Skip progress bar if too few files (analysis is fast)
        if ($total < $this->minFilesForProgress) {
            return;
        }

        $this->progressBar = new ProgressBar($this->section, $total);

        // Customize format with time estimates and memory
        $this->progressBar->setFormat(
            " %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%\n %message%",
        );

        // Style the progress bar
        $this->progressBar->setBarCharacter('<fg=green>▓</>');
        $this->progressBar->setEmptyBarCharacter('<fg=gray>░</>');
        $this->progressBar->setProgressCharacter('<fg=green>▓</>');

        $this->progressBar->setMessage('Starting analysis...');
        $this->progressBar->start();
    }

    public function advance(int $step = 1): void
    {
        $this->progressBar?->advance($step);
    }

    public function setMessage(string $message): void
    {
        $this->progressBar?->setMessage($message);
    }

    public function finish(): void
    {
        if ($this->progressBar === null) {
            return;
        }

        $this->progressBar->finish();
        $this->progressBar->clear();

        // Reset to null to free memory
        $this->progressBar = null;
    }
}
