<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineGenerator;
use Qualimetrix\Baseline\BaselineMigrator;
use Qualimetrix\Baseline\BaselineWriter;
use Qualimetrix\Baseline\MigrationReport;
use Qualimetrix\Baseline\V5BaselineReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `baseline:migrate` — converts a version 5 baseline into a version 10 one,
 * in a single run (ADR 0017).
 *
 * **Nothing is carried across structurally.** A v5 record is a rule name plus
 * an opaque hash and holds no magnitude, so there is no boundary in it to
 * translate; the new file is exactly what the current run captures. The
 * report is the whole of the continuity, which is why it names the dropped
 * entries individually while counting the rest: a dropped entry is an
 * acceptance the user is about to lose, and a number cannot be looked up.
 *
 * **`--force` guards the destination, not the conversion.** Without it the
 * command refuses a destination that is not recognisably a v5 file — already
 * version 10, unparseable, or absent — because a typo'd path otherwise
 * replaces a perfectly good v10 baseline with a fresh capture, silently
 * accepting every finding it had been holding the line on.
 *
 * **It carries a compare-and-swap token like every other writing command**
 * (ADR 0017). `migrate` reads a file, runs an analysis, and writes back over what
 * it read; without the token the whole analysis is a window in which another
 * process's write is lost. `update` and `cleanup` get the token from the
 * loader; {@see V5BaselineReader} gives v5 migration the corresponding token
 * from the exact bytes it parsed.
 */
#[AsCommand(
    name: 'baseline:migrate',
    description: 'Convert a version 5 baseline into the current format',
)]
final class BaselineMigrateCommand extends BaselineCommand
{
    public function __construct(
        private readonly BaselineRunInterface $baselineRun,
        private readonly BaselineGenerator $generator,
        private readonly BaselineMigrator $migrator,
        private readonly V5BaselineReader $v5Reader,
        private readonly BaselineWriter $writer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        BaselineCommandDefinition::addBaselineFileArgument($this, 'Path of the version 5 baseline file to convert');
        BaselineCommandDefinition::addMeasuredRunInput($this);

        $this
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Convert even when the destination is not a version 5 file, replacing it with a fresh capture',
            )
            ->setHelp(
                'Reads the version 5 file, captures everything that currently fires, and'
                . "\n" . 'writes the capture in the current format.' . "\n\n"
                . 'A version 5 entry records only that a finding existed, so no boundary'
                . "\n" . 'survives the conversion. Entries whose rule no longer reports anything'
                . "\n" . 'for their symbol are listed by name — they are the acceptances the'
                . "\n" . 'conversion drops.',
            );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $baselinePath */
        $baselinePath = $input->getArgument('baseline');
        $force = $input->getOption('force') === true;

        if ($this->migrator->destinationRequiresForce($baselinePath) && !$force) {
            $output->writeln(\sprintf(
                '<error>%s is not a version 5 baseline. Migrating would replace it with a fresh capture '
                . 'of everything currently reported — pass --force if that is intended.</error>',
                $baselinePath,
            ));

            return self::FAILURE;
        }

        $v5 = $this->v5Reader->readForMigration($baselinePath, $force);

        $context = $this->baselineRun->measure($input, $output);
        $capture = $this->generator->generate($context->violations(), $context->scope->paths());
        $result = $this->migrator->migrate($v5, $capture);

        $baseline = $v5->sourceContentHash === null
            ? $result->baseline->withExpectedSourceAbsence()
            : $result->baseline->withSourceContentHash($v5->sourceContentHash);

        $this->writer->write(
            $baseline,
            $baselinePath,
            $context->projectRoot,
        );

        $output->writeln(\sprintf(
            '<info>Migrated %s to version %d: %d entries written</info>',
            $baselinePath,
            Baseline::VERSION,
            $result->baseline->count(),
        ));

        self::reportMigration($result->report, $output);
        BaselineCaptureReporter::reportUncaptured($capture, $output);

        return self::SUCCESS;
    }

    private static function reportMigration(MigrationReport $report, OutputInterface $output): void
    {
        $output->writeln(\sprintf(
            '  carried: %d version 5 entr%s still reported, now recorded as %d entr%s',
            $report->carriedV5EntryCount,
            $report->carriedV5EntryCount === 1 ? 'y' : 'ies',
            $report->carriedV10EntryCount,
            $report->carriedV10EntryCount === 1 ? 'y' : 'ies',
        ));

        $output->writeln(\sprintf(
            '  fresh: %d entr%s for findings the old file did not cover',
            $report->freshV10EntryCount,
            $report->freshV10EntryCount === 1 ? 'y' : 'ies',
        ));

        if ($report->dropped === []) {
            $output->writeln('  dropped: none');
        } else {
            $output->writeln(\sprintf(
                '<comment>  dropped: %d version 5 entr%s no longer reported — their acceptance is gone:</comment>',
                \count($report->dropped),
                \count($report->dropped) === 1 ? 'y' : 'ies',
            ));

            foreach ($report->dropped as $dropped) {
                $output->writeln(\sprintf('<comment>    - %s %s</comment>', $dropped->symbolKey, $dropped->rule));
            }
        }

        if ($report->unreadableV5Records === []) {
            $output->writeln('  unreadable: none');
        } else {
            $output->writeln(\sprintf(
                '<comment>  unreadable: %d version 5 row%s could not be read — their acceptance is lost:</comment>',
                \count($report->unreadableV5Records),
                \count($report->unreadableV5Records) === 1 ? '' : 's',
            ));

            foreach ($report->unreadableV5Records as $unreadable) {
                $output->writeln(\sprintf('<comment>    - %s</comment>', $unreadable->describe()));
            }
        }

        if ($report->uncapturedGroupCount > 0) {
            $output->writeln(\sprintf(
                '<comment>  %d group(s) could not be captured — see below</comment>',
                $report->uncapturedGroupCount,
            ));
        }
    }
}
