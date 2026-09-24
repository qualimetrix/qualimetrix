<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Policy\Baseline\BaselineChannelRenamer;
use Qualimetrix\Analysis\Policy\Baseline\ChannelRenameMap;
use Qualimetrix\Analysis\Policy\Baseline\ChannelRenameRefusal;
use Qualimetrix\Analysis\Policy\Baseline\ChannelRenameReport;
use Qualimetrix\Infrastructure\Console\CommandLineSpelling;
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
            ->setHelp(self::withDocsPointer(
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
                . 'is the {error, exit_code, position} envelope every other' . "\n"
                . 'machine-readable refusal in this tool uses, so a script does not have' . "\n"
                . 'to read the outcome off the exit code alone.',
            ));
    }

    /**
     * The only one of the five `baseline:*` commands with a `--format`
     * option of its own: the shared ladder
     * asks the concrete command rather than reading the option itself, so
     * the other four are never asked for an option they never declared.
     */
    protected function refusalFormat(InputInterface $input): ?string
    {
        $format = $input->getOption('format');

        return \is_string($format) ? $format : null;
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        // Resolved before the file checks, not after: a caller that asked for
        // a machine format has asked for every outcome in it, and an
        // unreadable path is one of the outcomes.
        $format = self::knownFormat(CommandLineSpelling::option($input, 'format'));
        $baselinePath = CommandLineSpelling::requiredArgument($input, 'baseline');
        $mapPath = CommandLineSpelling::requiredArgument($input, 'map');

        self::assertBaselineReadable($baselinePath);
        self::assertMapReadable($mapPath);

        $map = self::loadMap($mapPath);
        $report = $this->carryBaseline($baselinePath, $map);

        ChannelRenameReporter::report($report, $format, $output);

        return self::SUCCESS;
    }

    /** @throws ConfigurationRefusal */
    private static function knownFormat(?string $format): string
    {
        if ($format !== 'text' && $format !== 'json') {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--format',
                'Unknown --format; expected text or json.',
            );
        }

        return $format;
    }

    /** @throws ConfigurationRefusal */
    private static function assertBaselineReadable(string $baselinePath): void
    {
        if (!is_file($baselinePath) || !is_readable($baselinePath)) {
            throw ConfigurationRefusal::aboutBaselineFileDocument(
                $baselinePath,
                \sprintf('Not a readable file: %s', $baselinePath),
            );
        }
    }

    /** @throws ConfigurationRefusal */
    private static function assertMapReadable(string $mapPath): void
    {
        if (!is_file($mapPath) || !is_readable($mapPath)) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                'map',
                \sprintf('Not a readable file: %s', $mapPath),
            );
        }
    }

    /** @throws ConfigurationRefusal */
    private static function loadMap(string $mapPath): ChannelRenameMap
    {
        try {
            return ChannelRenameMap::fromFile($mapPath);
        } catch (ChannelRenameRefusal $e) {
            throw ConfigurationRefusal::aboutCommandLineDocument(
                'map',
                $e->getMessage(),
                $e,
            );
        }
    }

    /** @throws ConfigurationRefusal */
    private function carryBaseline(string $baselinePath, ChannelRenameMap $map): ChannelRenameReport
    {
        // `ChannelRenameRefusal` is a plain `RuntimeException`, but the carry
        // understood the baseline envelope and declined, which is the user's
        // to fix, so it is normalized here rather than left for the shared
        // ladder's generic `RuntimeException` clause to answer with code 1.
        // Keep this normalization until `BaselineChannelRenamer` throws the
        // shared carrier directly.
        try {
            return $this->renamer->carry($baselinePath, $map);
        } catch (ChannelRenameRefusal $e) {
            throw ConfigurationRefusal::aboutBaselineFileDocument(
                $baselinePath,
                $e->getMessage(),
                $e,
            );
        }
    }
}
