<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Policy\Baseline\BaselineCleaner;
use Qualimetrix\Analysis\Policy\Baseline\BaselineCleanupCandidate;
use Qualimetrix\Analysis\Policy\Baseline\BaselineCleanupReason;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\BaselineWriter;
use Qualimetrix\Analysis\Policy\Baseline\EntrySelector;
use Qualimetrix\Infrastructure\Console\CommandLineSpelling;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `baseline:cleanup` — lists the entries nothing reports any more, and
 * removes exactly the ones a user names (ADR 0017).
 *
 * **Absence is not proof.** A loosened threshold, a changed `min_lines`, a
 * rewritten computed formula or an edited layer topology all silence a
 * finding without any code improving, and nothing cheap distinguishes them
 * from a repair. So removal is a user's assertion and never an inference:
 * with no `--remove` this command reports and writes nothing at all — not the
 * file, not its timestamp. There is deliberately no bulk form; a flag that
 * removed everything it had just listed would be the same inference wearing a
 * flag, and in a CI step it would delete the entries recording debt a
 * threshold edit had merely hidden.
 *
 * Each candidate is printed with its **selector** — a digest of the complete
 * identity, edge included — because two forbidden edges out of one class on
 * one channel agree on every other component and could not otherwise be told
 * apart on a command line.
 */
#[AsCommand(
    name: 'baseline:cleanup',
    description: 'List baseline entries nothing reports any more, and remove the ones you name',
)]
final class BaselineCleanupCommand extends BaselineCommand
{
    public function __construct(
        private readonly BaselineRunInterface $baselineRun,
        private readonly BaselineLoader $loader,
        private readonly BaselineCleaner $cleaner,
        private readonly BaselineWriter $writer,
        private readonly ChannelDeclarationRegistryInterface $declarations,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        BaselineCommandDefinition::addBaselineFileArgument($this, 'Path of the baseline file to inspect');
        BaselineCommandDefinition::addMeasuredRunInput($this);

        $this->addOption(
            'remove',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Selector of an entry to remove (repeat for more than one)',
            [],
        );

        BaselineCommandDefinition::addScopeOverrideOption($this);

        $this->setHelp(self::withDocsPointer(
            'Without --remove the command only reports: no entry is removed and the'
            . "\n" . 'file is not touched.' . "\n\n"
            . 'An entry is listed when the run reported nothing for its identity, or'
            . "\n" . 'when no rule declares its channel any more, or when the entry could'
            . "\n" . 'not be read at all. None of those proves the debt is gone — a'
            . "\n" . 'loosened threshold silences a finding just as effectively as a fix —'
            . "\n" . 'so removal is always yours to assert, one selector at a time.',
        ));
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $baselinePath = CommandLineSpelling::requiredArgument($input, 'baseline');
        // Spelled before the run, parsed after it: a value no command line can
        // write is refused before any work, and a malformed selector is
        // refused beside the candidates it should have been copied from.
        $written = CommandLineSpelling::options($input, 'remove');

        $measured = $this->measureAgainstBaseline($this->baselineRun, $this->loader, $input, $output, $baselinePath);

        if ($measured === null) {
            return self::FAILURE;
        }

        $context = $measured->context;
        $baseline = $measured->baseline;

        $candidates = $this->cleaner->candidates($baseline, $context->findings(), $this->declarations);
        self::reportCandidates($candidates, $output);

        $selectors = self::readSelectors($written);

        if ($selectors === []) {
            $output->writeln('<info>Nothing removed: pass --remove=SELECTOR for each entry you want gone.</info>');

            return self::SUCCESS;
        }

        $removal = $this->cleaner->remove($baseline, $selectors);

        foreach ($removal->notFound as $selector) {
            $output->writeln(\sprintf('<comment>No entry with selector %s; nothing removed for it.</comment>', $selector->value));
        }

        foreach ($removal->ambiguous as $selector) {
            $output->writeln(\sprintf(
                '<comment>Selector %s matches more than one entry; left alone rather than guessed.</comment>',
                $selector->value,
            ));
        }

        if ($removal->removed === []) {
            $output->writeln('<info>Nothing removed; the baseline is unchanged.</info>');

            return self::SUCCESS;
        }

        $this->writer->write($removal->baseline, $baselinePath, $context->projectRoot);

        $output->writeln(\sprintf(
            '<info>Removed %d entr%s; %d remain%s (%d including entries that cannot be applied).</info>',
            \count($removal->removed),
            \count($removal->removed) === 1 ? 'y' : 'ies',
            $removal->baseline->count(),
            $removal->baseline->count() === 1 ? 's' : '',
            $removal->baseline->totalCount(),
        ));

        return self::SUCCESS;
    }

    /**
     * `false` when some `--remove` value is not a selector at all.
     *
     * A malformed selector aborts the whole invocation instead of being
     * skipped: the user asked for a set of removals, and silently performing
     * the subset that parsed is how a typo turns into "it worked" over a file
     * that lost the wrong lines.
     *
     * @param list<string> $raw the `--remove` values as written
     *
     * @return list<EntrySelector>
     */
    private static function readSelectors(array $raw): array
    {
        $selectors = [];
        $invalid = [];

        foreach ($raw as $value) {
            $selector = EntrySelector::tryFromString($value);

            if ($selector === null) {
                $invalid[] = $value;

                continue;
            }

            $selectors[] = $selector;
        }

        if ($invalid !== []) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--remove',
                \sprintf(
                    'Not entry selectors (expected %d hexadecimal characters, as printed above): %s',
                    EntrySelector::LENGTH,
                    implode(', ', $invalid),
                ),
            );
        }

        return $selectors;
    }

    /**
     * @param list<BaselineCleanupCandidate> $candidates
     */
    private static function reportCandidates(array $candidates, OutputInterface $output): void
    {
        if ($candidates === []) {
            $output->writeln('<info>No entry is a removal candidate.</info>');

            return;
        }

        $output->writeln(\sprintf(
            '<info>%d entr%s could be removed:</info>',
            \count($candidates),
            \count($candidates) === 1 ? 'y' : 'ies',
        ));

        foreach ($candidates as $candidate) {
            $output->writeln(\sprintf(
                '  %s  %s  (%s)',
                $candidate->selector->value,
                $candidate->description,
                self::describeReason($candidate),
            ));
        }
    }

    private static function describeReason(BaselineCleanupCandidate $candidate): string
    {
        return match ($candidate->reason) {
            BaselineCleanupReason::Stale => 'nothing reported for this identity',
            BaselineCleanupReason::ChannelNotDeclared => 'no rule declares this channel',
            BaselineCleanupReason::ChannelIsConfigurationError => 'this channel reports a configuration error and'
                . ' cannot be accepted as debt',
            BaselineCleanupReason::Inert => 'cannot be applied: ' . ($candidate->inertReason?->description() ?? 'unreadable'),
        };
    }
}
