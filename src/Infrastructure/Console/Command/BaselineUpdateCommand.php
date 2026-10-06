<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdateDisposition;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdater;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdateResult;
use Qualimetrix\Analysis\Policy\Baseline\BaselineWriter;
use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineDocument;
use Qualimetrix\Analysis\Policy\Baseline\RunRuleCoverage;
use Qualimetrix\Core\FileTarget\PreparedTarget;
use Qualimetrix\Infrastructure\Console\CommandLineSpelling;
use Qualimetrix\Infrastructure\Console\RunTarget\StagedSignalGuard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `baseline:update` tightens acceptance by default (ADR 0017). Its explicit
 * options add new identities or record a changed exclusion population.
 *
 * The rule is direction-aware and stated over the whole group, not per
 * position: a stored `[40, 100]` whose member at 40 has been repaired is
 * *accepted* as `[100]`, because no level of severity holds more members than
 * before. An element-wise comparison would read rank 0 growing from 40 to 100
 * and decline, leaving a user no way to record an improvement short of
 * `baseline:generate`, which discards every other entry with it.
 *
 * The comparison itself is not made here: {@see BaselineUpdater} calls the
 * same acceptance primitive the ceiling applies at `check` time, so "not more
 * permissive" has one definition in the codebase rather than two that have to
 * be kept in agreement.
 *
 * **Nothing is written when nothing moved.** ADR 0017 requires a no-op command to
 * preserve the file's bytes, and `generated` alone changing would make every
 * scheduled `update` look like a change in version control.
 */
#[AsCommand(
    name: 'baseline:update',
    description: 'Tighten an existing baseline against the current findings',
)]
final class BaselineUpdateCommand extends BaselineCommand
{
    public function __construct(
        private readonly BaselineRunInterface $baselineRun,
        private readonly BaselineLoader $loader,
        private readonly BaselineDocumentReader $documentReader,
        private readonly BaselineUpdater $updater,
        private readonly BaselineWriter $writer,
        private readonly RunRuleCoverage $ruleCoverage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        BaselineCommandDefinition::addBaselineFileArgument($this, 'Path of the baseline file to update in place');
        BaselineCommandDefinition::addMeasuredRunInput($this);

        BaselineCommandDefinition::addScopeOverrideOption($this);
        $this->addOption('accept-new', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Accept only new identities of an exact channel (repeatable)', []);
        $this->addOption('record-exclusions', null, InputOption::VALUE_NONE, 'Record current exclusions and recapture only affected groups');

        $this->setHelp(self::withDocsPointer(
            'Replaces each entry with what its group reports now, but only where that'
            . "\n" . 'is no more permissive than what the entry already accepted. A group'
            . "\n" . 'that worsened is refused and its entry is written back unchanged.' . "\n\n"
            . 'An identity that no longer reports anything is left alone: a vanished'
            . "\n" . 'group is `baseline:cleanup`\'s business, and rewriting the entry to'
            . "\n" . 'nothing would delete an acceptance by inference.',
        ));
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $baselinePath = CommandLineSpelling::requiredArgument($input, 'baseline');

        [$channels, $recordExclusions] = self::updateOptions($input);
        $document = $this->documentReader->preflight($baselinePath);

        return $this->withPreparedTarget(
            $document->target,
            fn(PreparedTarget $prepared, StagedSignalGuard $guard): int => $this->updatePrepared($input, $output, $baselinePath, $channels, $recordExclusions, $document, $prepared, $guard),
        );
    }

    /** @param list<FindingChannel> $channels */
    private function updatePrepared(InputInterface $input, OutputInterface $output, string $baselinePath, array $channels, bool $recordExclusions, BaselineDocument $document, PreparedTarget $prepared, StagedSignalGuard $guard): int
    {
        $measured = $recordExclusions
            ? new LoadedBaselineRun($this->baselineRun->measure($input, $output), $this->loader->load($document))
            : $this->measureAgainstBaseline($this->baselineRun, $this->loader, $input, $output, $document);

        if ($measured === null) {
            return self::FAILURE;
        }

        $context = $measured->context;

        $gaps = $this->ruleCoverage->classify(array_map(static fn($entry) => $entry->identity, $measured->baseline->entries));
        $result = match (true) {
            $channels !== [] => $this->updater->acceptNew($measured->baseline, $context->findings(), $channels, $context->coverage, $this->ruleCoverage),
            $recordExclusions => $this->updater->recordExclusions($measured->baseline, $context->findings(), $context->coverage, $gaps),
            default => $this->updater->update($measured->baseline, $context->findings(), $context->coverage, $gaps),
        };
        if ($result->writeRefusal !== null) {
            self::report($result, $output);
            throw ConfigurationRefusal::aboutCommandLineInput('--record-exclusions', $result->writeRefusal->description());
        }

        self::report($result, $output);

        if (!$result->changed) {
            $output->writeln('<info>No entry moved; the baseline is unchanged.</info>');

            return self::SUCCESS;
        }

        $this->writer->write($result->baseline, $document->target, $context->projectRoot, $prepared, $guard->assertNotInterrupted(...));

        $output->writeln(\sprintf('<info>Baseline updated: %s</info>', $baselinePath));

        return self::SUCCESS;
    }

    /** @return array{list<FindingChannel>, bool} */
    private static function updateOptions(InputInterface $input): array
    {
        $channels = array_map(static fn(string $code): FindingChannel => new FindingChannel($code), CommandLineSpelling::options($input, 'accept-new'));
        $recordExclusions = $input->getOption('record-exclusions') === true;
        if ($channels !== [] && $recordExclusions) {
            throw ConfigurationRefusal::aboutCommandLineInput('--accept-new', '--accept-new and --record-exclusions cannot be combined.');
        }

        return [$channels, $recordExclusions];
    }

    private static function report(BaselineUpdateResult $result, OutputInterface $output): void
    {
        $counts = [];

        foreach ($result->outcomes as $index => $outcome) {
            $counts[$outcome->disposition->value] = ($counts[$outcome->disposition->value] ?? 0) + 1;
            $output->writeln(self::outcomeLine($result, $index));
        }

        foreach ($result->channelNotes as $channel => $reason) {
            $output->writeln(\sprintf('0 accepted: %s (%s)', $channel, $reason));
        }
        $output->writeln(\sprintf('%d accepted, %d re-recorded', $counts['accepted'] ?? 0, $counts['re-recorded'] ?? 0));
        $output->writeln(\sprintf(
            '%d updated, %d unchanged, %d removed, %d not compared, %d refused, %d skipped',
            $counts[BaselineUpdateDisposition::Updated->value] ?? 0,
            $counts[BaselineUpdateDisposition::Unchanged->value] ?? 0,
            $counts[BaselineUpdateDisposition::Removed->value] ?? 0,
            $counts[BaselineUpdateDisposition::NotCompared->value] ?? 0,
            $counts[BaselineUpdateDisposition::Refused->value] ?? 0,
            $counts[BaselineUpdateDisposition::Skipped->value] ?? 0,
        ));
    }

    private static function outcomeLine(BaselineUpdateResult $result, int $index): string
    {
        $outcome = $result->outcomes[$index];

        return match ($outcome->disposition) {
            BaselineUpdateDisposition::ReRecorded => self::reRecordedLine($result, $index),
            BaselineUpdateDisposition::Removed => \sprintf(
                '  removed  %s [%s] (%s)',
                $outcome->identity->describe(),
                $outcome->selector === null ? '' : $outcome->selector->value,
                $outcome->reasonCode ?? 'unknown reason',
            ),
            BaselineUpdateDisposition::NotCompared => \sprintf(
                '  not compared  %s (%s)',
                $outcome->identity->describe(),
                $outcome->reasonCode ?? 'unknown reason',
            ),
            BaselineUpdateDisposition::Skipped => \sprintf(
                '  skipped  %s (%s)',
                $outcome->identity->describe(),
                $outcome->reasonCode ?? 'not reported by this run',
            ),
            BaselineUpdateDisposition::Refused => \sprintf(
                '<comment>  refused  %s (%s)</comment>',
                $outcome->identity->describe(),
                $outcome->refusalReason?->description() ?? 'no reason given',
            ),
            default => \sprintf('  %s  %s', $outcome->disposition->value, $outcome->identity->describe()),
        };
    }

    private static function reRecordedLine(BaselineUpdateResult $result, int $index): string
    {
        $outcome = $result->outcomes[$index];

        return \sprintf(
            '  re-recorded  %s (exclusions changed: %s -> %s)',
            $outcome->identity->describe(),
            $outcome->previousLevel?->describe() ?? '',
            $outcome->currentLevel?->describe() ?? '',
        );
    }

}
