<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\BaselineWriter;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'baseline:cleanup',
    description: 'Remove stale entries from baseline (files that no longer exist)',
)]
final class BaselineCleanupCommand extends Command
{
    public function __construct(
        private readonly BaselineLoader $baselineLoader,
        private readonly BaselineWriter $baselineWriter,
        private readonly ConfigurationProviderInterface $configurationProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'baseline',
            InputArgument::REQUIRED,
            'Path to baseline file',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $baselinePath */
        $baselinePath = $input->getArgument('baseline');

        if (!file_exists($baselinePath)) {
            $output->writeln(\sprintf('<error>Baseline file not found: %s</error>', $baselinePath));

            return self::FAILURE;
        }

        // Load baseline. The "was N" figure counts the whole file, inert
        // entries included: this command removes them too, and a total that
        // silently omitted them would describe a file the user does not have.
        $baseline = $this->baselineLoader->load($baselinePath);
        $originalCount = $baseline->totalCount();

        // Find stale entries (symbols whose files no longer exist)
        $cleanedEntries = [];
        $cleanedInert = [];
        $staleKeys = [];
        $staleCount = 0;

        foreach ($baseline->entries as $entry) {
            if ($this->symbolFileExists($entry->identity->symbolKey)) {
                $cleanedEntries[] = $entry;
            } else {
                $staleKeys[$entry->identity->symbolKey] = true;
                ++$staleCount;
            }
        }

        // Inert entries follow their symbol too: the file being gone is a
        // fact about the key, not about whether the entry parsed — and they
        // are counted, because a removal the total does not mention reads as
        // "nothing changed" over a file that just lost data.
        foreach ($baseline->inertEntries as $inert) {
            if ($this->symbolFileExists($inert->symbolKey)) {
                $cleanedInert[] = $inert;
            } else {
                $staleKeys[$inert->symbolKey] = true;
                ++$staleCount;
            }
        }

        // If no stale entries, nothing to do
        if ($staleCount === 0) {
            $output->writeln('<info>No stale entries found. Baseline is up to date.</info>');

            return self::SUCCESS;
        }

        // Create new baseline without stale entries. The loaded content hash
        // travels with it, so the write refuses to clobber a file someone
        // else changed in the meantime.
        $cleanedBaseline = new Baseline(
            generated: $baseline->generated,
            scope: $baseline->scope,
            entries: $cleanedEntries,
            inertEntries: $cleanedInert,
            sourceContentHash: $baseline->sourceContentHash,
        );

        // Write cleaned baseline
        $this->baselineWriter->write(
            $cleanedBaseline,
            $baselinePath,
            $this->configurationProvider->getConfiguration()->projectRoot,
        );

        // Output statistics
        $output->writeln(\sprintf(
            '<info>Removed %d stale entries from %d symbols</info>',
            $staleCount,
            \count($staleKeys),
        ));

        if ($output->isVerbose()) {
            $output->writeln('<comment>Removed symbols:</comment>');
            foreach (array_keys($staleKeys) as $canonical) {
                $output->writeln(\sprintf('  - %s', $canonical));
            }
        }

        $newCount = $cleanedBaseline->totalCount();
        $output->writeln(\sprintf(
            '<info>Baseline updated: %d entries (was %d)</info>',
            $newCount,
            $originalCount,
        ));

        return self::SUCCESS;
    }

    /**
     * Whether the file a symbol key names still exists.
     *
     * Only `file:` keys carry a path; `class:`, `method:` and `ns:` keys are
     * FQN-based and carry no file information, so they are always kept.
     */
    private function symbolFileExists(string $canonical): bool
    {
        if (!str_starts_with($canonical, 'file:')) {
            return true;
        }

        return file_exists(substr($canonical, 5));
    }
}
