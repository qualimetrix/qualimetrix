<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileFormat;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileReportInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Handles profiling output: summary to stderr or export to file.
 *
 * An export that cannot happen is refused, never reported beside a finished
 * run: {@see self::refuseImpossibleExport()} before analysis, and a typed
 * refusal from {@see self::present()} when the write itself fails — the same
 * contract `--output` keeps.
 */
final class ProfilePresenter
{
    public function __construct(
        private readonly ProfileReportInterface $profileReport,
        private readonly ErrorStream $errorStream,
        private readonly ProfileSummaryRenderer $profileRenderer = new ProfileSummaryRenderer(),
    ) {}

    /**
     * Refuses, before any analysis runs, a `--profile-format` outside the
     * closed set and a `--profile` target that cannot be written.
     *
     * The format is judged whenever the option is written, even without
     * `--profile`: a value outside the set is wrong wherever it stands. The
     * target check is a fast precheck, not a guarantee — writability can
     * change during the run, which {@see self::present()} answers on its own.
     */
    public static function refuseImpossibleExport(InputInterface $input): void
    {
        if ($input->hasOption('profile-format')) {
            self::format($input->getOption('profile-format'));
        }

        $target = $input->hasOption('profile') ? $input->getOption('profile') : false;
        if (!\is_string($target)) {
            return;
        }

        if (trim($target) === '') {
            throw self::refusal(
                '--profile',
                'Option --profile was written with an empty value ("--profile="). '
                . 'Name a file to export to, or write --profile alone for a summary on stderr.',
            );
        }

        $directory = \dirname($target);
        if (file_exists($target) ? !is_writable($target) : !is_dir($directory) || !is_writable($directory)) {
            throw self::refusal(
                '--profile',
                \sprintf('Option --profile names "%s", which is not writable, or whose directory "%s" does not exist.', $target, $directory),
            );
        }
    }

    /**
     * Outputs profiling results if profiling was enabled.
     *
     * @throws ConfigurationRefusal when the export cannot be written
     */
    public function present(InputInterface $input, OutputInterface $output): void
    {
        $output = $this->errorStream->writer($output);
        if (!$this->profileReport->isEnabled()) {
            return;
        }

        $profileOption = $input->getOption('profile');

        // If --profile without value, output summary to stderr
        if ($profileOption === null) {
            $summary = $this->profileRenderer->render($this->profileReport->summary());
            $output->writeln('', OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL);
            $output->writeln($summary, OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL);

            return;
        }

        $profileData = $this->profileReport->export(self::format($input->getOption('profile-format') ?? 'json'));

        // Atomic write: write to temp file first, then rename
        $tmpFile = $profileOption . '.tmp.' . getmypid();
        $writeResult = @file_put_contents($tmpFile, $profileData);

        if ($writeResult === false) {
            throw self::refusal('--profile', \sprintf('Failed to write the --profile export to temporary file %s', $tmpFile));
        }

        if (!@rename($tmpFile, $profileOption)) {
            // Clean up temp file on rename failure
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }

            throw self::refusal('--profile', \sprintf('Failed to rename the --profile export %s to %s', $tmpFile, $profileOption));
        }

        $output->writeln(
            \sprintf('<info>Profile exported to %s</info>', $profileOption),
            OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL,
        );
    }

    private static function format(mixed $value): ProfileFormat
    {
        $format = \is_string($value) ? ProfileFormat::tryFrom($value) : null;

        if ($format === null) {
            throw self::refusal('--profile-format', \sprintf(
                'Invalid value "%s" for --profile-format. Expected one of: %s.',
                \is_scalar($value) ? (string) $value : get_debug_type($value),
                implode(', ', array_map(static fn(ProfileFormat $case): string => $case->value, ProfileFormat::cases())),
            ));
        }

        return $format;
    }

    private static function refusal(string $option, string $summary): ConfigurationRefusal
    {
        return ConfigurationRefusal::aboutCommandLineInput($option, $summary);
    }
}
