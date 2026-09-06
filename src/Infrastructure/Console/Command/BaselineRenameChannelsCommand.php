<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Policy\Baseline\BaselineChannelRenamer;
use Qualimetrix\Analysis\Policy\Baseline\ChannelRenameMap;
use RuntimeException;
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
                . '"baseline:cleanup --remove SELECTOR" stops addressing it.' . "\n\n"
                . 'A refusal is reported in the chosen format too: with --format=json it' . "\n"
                . 'is an object with an "error" key, so a script does not have to read the' . "\n"
                . 'outcome off the exit code alone.',
            );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $baselinePath */
        $baselinePath = $input->getArgument('baseline');
        /** @var string $mapPath */
        $mapPath = $input->getArgument('map');

        $format = $input->getOption('format');

        // Resolved before the file checks, not after: a caller that asked for
        // a machine format has asked for every outcome in it, and an
        // unreadable path is one of the outcomes. The only failure this
        // command still answers in prose is a `--format` value it could not
        // read, where there is no chosen format to answer in.
        if ($format !== 'text' && $format !== 'json') {
            $output->writeln('<error>Unknown --format; expected text or json.</error>');

            return self::EXIT_INVALID_INPUT;
        }

        foreach ([$baselinePath, $mapPath] as $path) {
            if (!is_file($path) || !is_readable($path)) {
                return ChannelRenameReporter::refuse(\sprintf('Not a readable file: %s', $path), $format, $output);
            }
        }

        try {
            $report = $this->renamer->carry($baselinePath, ChannelRenameMap::fromFile($mapPath));
        } catch (RuntimeException $e) {
            // Only the machine format is answered here. Text is BaselineCommand's,
            // unchanged, because that is where a refusal still carries its trace
            // under -v — and the trace matters most exactly when the guess that a
            // refusal is the user's to fix turns out wrong. A JSON caller trades
            // it for an outcome it can parse.
            //
            // Every refusal this command can reach is a RuntimeException: a map or
            // content refusal, a compare-and-swap conflict, a file that could not
            // be replaced. Anything else is a defect in this tool rather than an
            // outcome of the carry, and goes on to be labelled as one — a bug is
            // not a machine-readable result.
            if ($format !== 'json') {
                throw $e;
            }

            return ChannelRenameReporter::refuse($e->getMessage(), $format, $output);
        }

        ChannelRenameReporter::report($report, $format, $output);

        return self::SUCCESS;
    }
}
