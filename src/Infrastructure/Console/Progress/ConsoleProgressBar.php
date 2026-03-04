<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Console\Progress;

use AiMessDetector\Core\Progress\ProgressReporter;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console progress bar implementation using Symfony ProgressBar.
 *
 * Automatically disables progress bar for:
 * - Non-TTY output (CI, pipes)
 * - File count below threshold
 *
 * Uses null-safe operations to ensure no errors when progress bar is disabled.
 */
final class ConsoleProgressBar implements ProgressReporter
{
    private ?ProgressBar $progressBar = null;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly int $minFilesForProgress = 10,
    ) {}

    public function start(int $total): void
    {
        // Skip progress bar if too few files (analysis is fast)
        if ($total < $this->minFilesForProgress) {
            return;
        }

        // Skip progress bar for non-console output (e.g., file redirection)
        if (!$this->output instanceof ConsoleOutputInterface) {
            return;
        }

        // Create progress bar with a dedicated section
        $section = $this->output->section();
        $this->progressBar = new ProgressBar($section, $total);

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
