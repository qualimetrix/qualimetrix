<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Policy\Baseline\BaselineChannelRenamer;
use Qualimetrix\Analysis\Policy\Baseline\ChannelRenameMap;
use Qualimetrix\Analysis\Policy\Baseline\ChannelRenameReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `baseline:rename-channels` — carries a baseline file onto a renamed
 * channel vocabulary, along a map the user supplies.
 *
 * **The one baseline command that measures nothing.** The other four run an
 * analysis first, because what they do depends on what the code reports
 * today. A carry does not: it substitutes one declared name for another in a
 * file, and consulting the tree would only let a run that is not the run the
 * entries were accepted against change the answer. That is why this command
 * takes no `--preset`, no `--config` and no paths — there is no measured set
 * for them to define.
 *
 * The map is an argument rather than a fixed location because the file the
 * product ships its own renames in is not shipped to consumers; a user
 * carries their baseline with whatever map their upgrade came with.
 */
#[AsCommand(
    name: 'baseline:rename-channels',
    description: 'Rewrite the channel names in a baseline file along a declared rename map',
)]
final class BaselineRenameChannelsCommand extends BaselineCommand
{
    public function __construct(
        private readonly BaselineChannelRenamer $renamer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        BaselineCommandDefinition::addBaselineFileArgument($this, 'Path of the baseline file to carry');

        $this
            ->addArgument(
                'map',
                InputArgument::REQUIRED,
                'Path of the tab-separated rename map (columns: old, new, reason)',
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format (text, json)',
                'text',
            )
            ->setHelp(
                'Rewrites the "channel" field of the entries the map names, and nothing' . "\n"
                . 'else: subject keys, occurrence, count, magnitudes, mode and edge are' . "\n"
                . 'carried through untouched, and no analysis is run.' . "\n\n"
                . 'A new name is NOT checked against the channels this build declares.' . "\n"
                . 'The carry ships before the renames it exists to perform, so a name it' . "\n"
                . 'writes is normally one this build has never heard of; only the form of' . "\n"
                . 'a name is validated. Until the release that declares it lands, "check"' . "\n"
                . 'reports such an entry as one it cannot apply.' . "\n\n"
                . 'Entry selectors are derived from the identity a channel name is part' . "\n"
                . 'of, so a carried entry gets a new selector: a saved' . "\n"
                . '"baseline:cleanup --remove SELECTOR" stops addressing it.',
            );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $baselinePath */
        $baselinePath = $input->getArgument('baseline');
        /** @var string $mapPath */
        $mapPath = $input->getArgument('map');

        foreach ([$baselinePath, $mapPath] as $path) {
            if (!is_file($path) || !is_readable($path)) {
                $output->writeln(\sprintf('<error>Not a readable file: %s</error>', $path));

                return self::EXIT_INVALID_INPUT;
            }
        }

        $format = $input->getOption('format');

        if ($format !== 'text' && $format !== 'json') {
            $output->writeln('<error>Unknown --format; expected text or json.</error>');

            return self::EXIT_INVALID_INPUT;
        }

        $report = $this->renamer->carry($baselinePath, ChannelRenameMap::fromFile($mapPath));

        if ($format === 'json') {
            self::reportAsJson($report, $output);
        } else {
            self::reportAsText($report, $output);
        }

        return self::SUCCESS;
    }

    private static function reportAsText(ChannelRenameReport $report, OutputInterface $output): void
    {
        $output->writeln($report->written
            ? \sprintf(
                '<info>Carried %d of %d entries onto a new channel name.</info>',
                $report->renamedEntries,
                $report->totalEntries,
            )
            : \sprintf(
                '<info>No entry of the %d in this baseline matched the map; the file is unchanged.</info>',
                $report->totalEntries,
            ));

        foreach ($report->idleRows() as $old) {
            $output->writeln(\sprintf('<comment>Declared rename of "%s" matched no entry.</comment>', $old));
        }

        foreach ($report->unreadable as $reason => $count) {
            $output->writeln(\sprintf(
                '<comment>%d entr%s carried through unchanged because %s.</comment>',
                $count,
                $count === 1 ? 'y was' : 'ies were',
                $reason,
            ));
        }
    }

    private static function reportAsJson(ChannelRenameReport $report, OutputInterface $output): void
    {
        $output->writeln(json_encode([
            'written' => $report->written,
            'entries' => $report->totalEntries,
            'renamed' => $report->renamedEntries,
            'rows' => $report->rowHits,
            'idle_rows' => $report->idleRows(),
            'unreadable' => $report->unreadable,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT));
    }
}
