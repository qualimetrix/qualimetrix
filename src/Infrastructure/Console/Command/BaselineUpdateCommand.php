<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\BaselineUpdateDisposition;
use Qualimetrix\Baseline\BaselineUpdater;
use Qualimetrix\Baseline\BaselineUpdateResult;
use Qualimetrix\Baseline\BaselineWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `baseline:update` — moves every entry it can toward stricter, and nothing
 * toward more permissive (§7 of the baseline-ceiling plan).
 *
 * The rule is direction-aware and stated over the whole group, not per
 * position: a stored `[40, 100]` whose 40-line duplicate has been deleted is
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
 * **Nothing is written when nothing moved.** §6 requires a no-op command to
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
        private readonly BaselineUpdater $updater,
        private readonly BaselineWriter $writer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        BaselineCommandDefinition::addBaselineFileArgument($this, 'Path of the baseline file to update in place');
        BaselineCommandDefinition::addMeasuredRunInput($this);

        $this
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Write even when this run does not cover the scope the baseline records',
            )
            ->setHelp(
                'Replaces each entry with what its group reports now, but only where that'
                . "\n" . 'is no more permissive than what the entry already accepted. A group'
                . "\n" . 'that worsened is refused and its entry is written back unchanged.' . "\n\n"
                . 'An identity that no longer reports anything is left alone: a vanished'
                . "\n" . 'group is `baseline:cleanup`\'s business, and rewriting the entry to'
                . "\n" . 'nothing would delete an acceptance by inference.',
            );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $baselinePath */
        $baselinePath = $input->getArgument('baseline');
        $force = $input->getOption('force') === true;

        // The run first, then the file (§5.4): a `computed.*` channel resolves
        // its shape and direction from configuration this run resolves, and a
        // file read before it loads every such entry inert — which here means
        // silently declining to tighten entries `check` applies normally.
        $context = $this->baselineRun->measure($input, $output);
        $baseline = $this->loader->load($baselinePath);

        if (!$this->assertScopeCovers($context->scope, $baseline->scope, $force, $output)) {
            return self::FAILURE;
        }

        $result = $this->updater->update($baseline, $context->violations(), $context->scope);

        self::report($result, $output);

        if (!self::changed($baseline, $result->baseline)) {
            $output->writeln('<info>No entry moved; the baseline is unchanged.</info>');

            return self::SUCCESS;
        }

        $this->writer->write($result->baseline, $baselinePath, $context->projectRoot);

        $output->writeln(\sprintf('<info>Baseline updated: %s</info>', $baselinePath));

        return self::SUCCESS;
    }

    /**
     * Whether the update produced entries that differ from the loaded ones.
     *
     * Compared as the payloads that would be written rather than by counting
     * `Updated` outcomes: an entry whose group reports exactly what it
     * already recorded is legitimately "updated" and changes nothing, and
     * rewriting the file for it would move `generated` on every run.
     * {@see BaselineUpdater} preserves the loaded order, so a positional
     * comparison is a comparison of the same entries.
     */
    private static function changed(Baseline $loaded, Baseline $updated): bool
    {
        return self::payloads($loaded) !== self::payloads($updated);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function payloads(Baseline $baseline): array
    {
        $payloads = [];

        foreach ($baseline->entries as $entry) {
            $payloads[] = $entry->toArray();
        }

        return $payloads;
    }

    private static function report(BaselineUpdateResult $result, OutputInterface $output): void
    {
        $counts = [];

        foreach ($result->outcomes as $outcome) {
            $counts[$outcome->disposition->value] = ($counts[$outcome->disposition->value] ?? 0) + 1;

            $line = match ($outcome->disposition) {
                BaselineUpdateDisposition::Updated => \sprintf('  updated  %s', $outcome->identity->describe()),
                BaselineUpdateDisposition::Skipped => \sprintf(
                    '  skipped  %s (not reported by this run)',
                    $outcome->identity->describe(),
                ),
                BaselineUpdateDisposition::Refused => \sprintf(
                    '<comment>  refused  %s (%s)</comment>',
                    $outcome->identity->describe(),
                    $outcome->refusalReason?->description() ?? 'no reason given',
                ),
            };

            $output->writeln($line);
        }

        $output->writeln(\sprintf(
            '%d updated, %d refused, %d skipped',
            $counts[BaselineUpdateDisposition::Updated->value] ?? 0,
            $counts[BaselineUpdateDisposition::Refused->value] ?? 0,
            $counts[BaselineUpdateDisposition::Skipped->value] ?? 0,
        ));
    }
}
