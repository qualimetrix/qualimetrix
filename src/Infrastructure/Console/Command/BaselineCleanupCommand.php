<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\BaselineWriter;
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

        // Load baseline
        $baseline = $this->baselineLoader->load($baselinePath);
        $originalCount = $baseline->count();

        // Find stale entries (symbols whose files no longer exist)
        $cleanedEntries = [];
        $staleKeys = [];

        foreach ($baseline->entries as $canonical => $entries) {
            $filePath = $this->extractFilePathFromCanonical($canonical);

            if ($filePath === null || file_exists($filePath)) {
                // Keep entries without file path info or with existing files
                $cleanedEntries[$canonical] = $entries;
            } else {
                $staleKeys[] = $canonical;
            }
        }

        $staleCount = $originalCount - array_sum(array_map(count(...), $cleanedEntries));

        // If no stale entries, nothing to do
        if ($staleCount === 0) {
            $output->writeln('<info>No stale entries found. Baseline is up to date.</info>');

            return self::SUCCESS;
        }

        // Create new baseline without stale entries
        $cleanedBaseline = new Baseline(
            version: $baseline->version,
            generated: $baseline->generated,
            entries: $cleanedEntries,
        );

        // Write cleaned baseline
        $this->baselineWriter->write($cleanedBaseline, $baselinePath);

        // Output statistics
        $output->writeln(\sprintf(
            '<info>Removed %d stale entries from %d symbols</info>',
            $staleCount,
            \count($staleKeys),
        ));

        if ($output->isVerbose()) {
            $output->writeln('<comment>Removed symbols:</comment>');
            foreach ($staleKeys as $canonical) {
                $entryCount = \count($baseline->entries[$canonical] ?? []);
                $output->writeln(\sprintf('  - %s (%d entries)', $canonical, $entryCount));
            }
        }

        $newCount = $cleanedBaseline->count();
        $output->writeln(\sprintf(
            '<info>Baseline updated: %d violations (was %d)</info>',
            $newCount,
            $originalCount,
        ));

        return self::SUCCESS;
    }

    /**
     * Extracts file path from canonical symbol path if available.
     *
     * Canonical formats:
     * - file:path/to/file.php -> returns path/to/file.php
     * - class:App\Class, method:App\Class::method, ns:App -> returns null (no file info)
     */
    private function extractFilePathFromCanonical(string $canonical): ?string
    {
        if (str_starts_with($canonical, 'file:')) {
            return substr($canonical, 5);
        }

        return null;
    }
}
