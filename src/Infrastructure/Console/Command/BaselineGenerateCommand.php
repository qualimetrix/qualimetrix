<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineEntryMode;
use Qualimetrix\Baseline\BaselineGenerator;
use Qualimetrix\Baseline\BaselineWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `baseline:generate` — captures the run's findings as a new baseline file
 * (§7 of the baseline-ceiling plan).
 *
 * **It refuses to overwrite.** A baseline is the record of what a team
 * decided to accept; regenerating it silently discards every deliberately
 * tightened entry it held, and the accident is easy — the same command line
 * that created the file recreates it. `--force` is the assertion that the
 * loss is intended.
 *
 * **`--mode=ratchet` writes no `mode` key at all.** The default is not
 * spelled in the file because an entry with no `mode` *is* a ratchet entry
 * (§6); writing `mode: ratchet` would make the absence of the key mean
 * something else, and every existing file would then be ambiguous.
 * `--mode=suppress` writes `mode: suppress` on every entry it captures — the
 * old "this identity is accepted whatever it reports" behaviour, kept as an
 * explicit, per-entry opt-out of the ceiling rather than as a way of running
 * the tool.
 */
#[AsCommand(
    name: 'baseline:generate',
    description: 'Capture the current findings as a new baseline file',
)]
final class BaselineGenerateCommand extends BaselineCommand
{
    private const string MODE_RATCHET = 'ratchet';
    private const string MODE_SUPPRESS = 'suppress';

    public function __construct(
        private readonly BaselineRunInterface $baselineRun,
        private readonly BaselineGenerator $generator,
        private readonly BaselineWriter $writer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        BaselineCommandDefinition::addBaselineFileArgument($this, 'Path of the baseline file to write');
        BaselineCommandDefinition::addMeasuredRunInput($this);

        $this
            ->addOption(
                'mode',
                null,
                InputOption::VALUE_REQUIRED,
                \sprintf(
                    'How captured entries are judged later: %s (bound each group by what it reports now) '
                    . 'or %s (accept the identity whatever it reports)',
                    self::MODE_RATCHET,
                    self::MODE_SUPPRESS,
                ),
                self::MODE_RATCHET,
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Overwrite an existing baseline file, discarding the acceptances it records',
            )
            ->setHelp(
                'Captures every finding the configured analysis reports — the same set'
                . "\n" . '`qmx check` measures — and records the level each group is accepted at.' . "\n\n"
                . 'Exclusions and `@qmx-ignore` come from qmx.yaml and from the source itself,'
                . "\n" . 'never from this command: a set a flag could move is a set this command'
                . "\n" . 'and `qmx check` could disagree about.',
            );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $baselinePath */
        $baselinePath = $input->getArgument('baseline');
        $force = $input->getOption('force') === true;

        $mode = $this->readMode($input, $output);
        if ($mode === false) {
            return self::EXIT_INVALID_INPUT;
        }

        if (file_exists($baselinePath) && !$force) {
            $output->writeln(\sprintf(
                '<error>%s already exists. Regenerating discards every acceptance it records — '
                . 'pass --force if that is intended, or use `baseline:update` to tighten it in place.</error>',
                $baselinePath,
            ));

            return self::FAILURE;
        }

        $context = $this->baselineRun->measure($input, $output);
        $capture = $this->generator->generate($context->violations(), $context->scope);
        $baseline = $mode === BaselineEntryMode::Suppress
            ? self::withMode($capture->baseline, BaselineEntryMode::Suppress)
            : $capture->baseline;

        $this->writer->write($baseline, $baselinePath, $context->projectRoot);

        $output->writeln(\sprintf(
            '<info>Baseline with %d entries written to %s</info>',
            $baseline->count(),
            $baselinePath,
        ));

        BaselineCaptureReporter::reportUncaptured($capture, $output);

        return self::SUCCESS;
    }

    /**
     * `false` when the value is not a mode at all — distinct from `null`,
     * which is the ratchet default and the one mode that writes nothing.
     */
    private function readMode(InputInterface $input, OutputInterface $output): BaselineEntryMode|false|null
    {
        $raw = $input->getOption('mode');

        if ($raw === self::MODE_RATCHET) {
            return null;
        }

        if ($raw === self::MODE_SUPPRESS) {
            return BaselineEntryMode::Suppress;
        }

        $output->writeln(\sprintf(
            '<error>Unknown --mode value "%s". Expected %s or %s.</error>',
            \is_scalar($raw) ? (string) $raw : \gettype($raw),
            self::MODE_RATCHET,
            self::MODE_SUPPRESS,
        ));

        return false;
    }

    /**
     * Stamps a mode onto every captured entry.
     *
     * {@see BaselineGenerator} has no opinion about `mode` — it captures what
     * a group reports, and whether that record is later read as a ceiling or
     * as a blanket acceptance is this command's flag, not a property of the
     * measurement. Rebuilding the entries here keeps that separation instead
     * of threading a CLI concern through the capture.
     */
    private static function withMode(Baseline $baseline, BaselineEntryMode $mode): Baseline
    {
        $entries = [];

        foreach ($baseline->entries as $entry) {
            $entries[] = new BaselineEntry($entry->identity, $entry->magnitudes, $entry->count, $mode);
        }

        return new Baseline(
            generated: $baseline->generated,
            scope: $baseline->scope,
            entries: $entries,
            inertEntries: $baseline->inertEntries,
        );
    }
}
