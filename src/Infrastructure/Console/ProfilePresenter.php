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
 * refusal from {@see self::present()} when the write itself fails. The latter
 * comes after the report is published, so the command presents it on stderr
 * and leaves stdout to the report.
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
     * target is judged by {@see ArtifactFile}, which also makes the write.
     */
    public static function refuseImpossibleExport(InputInterface $input): void
    {
        self::format($input);

        $target = self::exportTarget($input);
        if ($target === null) {
            return;
        }

        if (trim($target->path) === '') {
            throw self::refusal(
                '--profile',
                'Option --profile was written with an empty value ("--profile="). '
                . 'Name a file to export to, or write --profile alone for a summary on stderr.',
            );
        }

        $target->refuseUnwritable();
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

        $target = self::exportTarget($input);

        // If --profile without value, output summary to stderr
        if ($target === null) {
            $summary = $this->profileRenderer->render($this->profileReport->summary());
            $output->writeln('', OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL);
            $output->writeln($summary, OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL);

            return;
        }

        $target->replaceWith($this->profileReport->export(self::format($input) ?? ProfileFormat::Json));

        $output->writeln(
            \sprintf('<info>Profile exported to %s</info>', $target->path),
            OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL,
        );
    }

    /**
     * The file `--profile` names; null when the option was not written, or
     * written alone — which an array input spells `true` rather than null.
     */
    private static function exportTarget(InputInterface $input): ?ArtifactFile
    {
        $value = $input->hasOption('profile') ? $input->getOption('profile') : false;

        return $value === false || $value === null || $value === true
            ? null
            : new ArtifactFile(CommandLineSpelling::of($value, '--profile'), '--profile');
    }

    private static function format(InputInterface $input): ?ProfileFormat
    {
        $value = CommandLineSpelling::option($input, 'profile-format');
        if ($value === null) {
            return null;
        }

        return ProfileFormat::tryFrom($value) ?? throw self::refusal('--profile-format', \sprintf(
            'Invalid value "%s" for --profile-format. Expected one of: %s.',
            $value,
            implode(', ', array_map(static fn(ProfileFormat $case): string => $case->value, ProfileFormat::cases())),
        ));
    }

    private static function refusal(string $option, string $summary): ConfigurationRefusal
    {
        return ConfigurationRefusal::aboutCommandLineInput($option, $summary);
    }
}
