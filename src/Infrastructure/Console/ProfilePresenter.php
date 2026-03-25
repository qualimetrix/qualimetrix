<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Reporting\Profile\ProfileSummaryRenderer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Handles profiling output: summary to stderr or export to file.
 */
final class ProfilePresenter
{
    public function __construct(
        private readonly ProfilerHolder $profilerHolder,
        private readonly ProfileSummaryRenderer $profileRenderer = new ProfileSummaryRenderer(),
    ) {}

    /**
     * Outputs profiling results if profiling was enabled.
     */
    public function present(InputInterface $input, OutputInterface $output): void
    {
        $profiler = $this->profilerHolder->get();

        if (!$profiler->isEnabled()) {
            return;
        }

        $profileOption = $input->getOption('profile');

        // If --profile without value, output summary to stderr
        if ($profileOption === null) {
            $summary = $this->profileRenderer->render($profiler->getSummary());
            $output->writeln('', OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL);
            $output->writeln($summary, OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL);

            return;
        }

        // Export to file
        /** @var string $formatOption */
        $formatOption = $input->getOption('profile-format') ?? 'json';

        // Validate format
        if (!\in_array($formatOption, ['json', 'chrome-tracing'], true)) {
            $output->writeln(
                \sprintf('<error>Invalid profile format: %s. Valid formats: json, chrome-tracing</error>', $formatOption),
                OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL,
            );

            return;
        }

        /** @var 'json'|'chrome-tracing' $format */
        $format = $formatOption;

        $profileData = $profiler->export($format);

        // Atomic write: write to temp file first, then rename
        $tmpFile = $profileOption . '.tmp.' . getmypid();
        $writeResult = @file_put_contents($tmpFile, $profileData);

        if ($writeResult === false) {
            $output->writeln(
                \sprintf('<error>Failed to write profile data to temporary file %s</error>', $tmpFile),
                OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL,
            );

            return;
        }

        if (!rename($tmpFile, $profileOption)) {
            $output->writeln(
                \sprintf('<error>Failed to rename temporary profile file %s to %s</error>', $tmpFile, $profileOption),
                OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL,
            );

            // Clean up temp file on rename failure
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }

            return;
        }

        $output->writeln(
            \sprintf('<info>Profile exported to %s</info>', $profileOption),
            OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL,
        );
    }
}
