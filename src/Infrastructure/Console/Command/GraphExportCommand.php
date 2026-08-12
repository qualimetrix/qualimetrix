<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Pipeline\DependencyGraphAnalyzerInterface;
use Qualimetrix\Analysis\Pipeline\IncompleteAnalysisException;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Infrastructure\Console\OutputHelper;
use Qualimetrix\Reporting\GraphProjection\Contract\DependencyGraphProjectionInterface;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphProjectionRequest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'graph:export',
    description: 'Export dependency graph for visualization (DOT, JSON)',
)]
final class GraphExportCommand extends Command
{
    private const int EXIT_ANALYSIS_INCOMPLETE = 4;

    public function __construct(
        private readonly DependencyGraphAnalyzerInterface $analyzer,
        private readonly DependencyGraphProjectionInterface $projection,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'paths',
                InputArgument::IS_ARRAY | InputArgument::REQUIRED,
                'Paths to analyze',
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output file (default: stdout)',
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format (dot, json)',
                'dot',
            )
            ->addOption(
                'direction',
                null,
                InputOption::VALUE_REQUIRED,
                'Graph direction (LR, TB, RL, BT)',
                'LR',
            )
            ->addOption(
                'no-clusters',
                null,
                InputOption::VALUE_NONE,
                'Do not group by namespace',
            )
            ->addOption(
                'namespace',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Include only these namespaces',
            )
            ->addOption(
                'exclude-namespace',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exclude these namespaces',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $rawPaths */
        $rawPaths = $input->getArgument('paths');

        $cwd = AbsolutePath::fromString((string) getcwd());
        $paths = array_map(
            static fn(string $raw): AbsolutePath => PathFactory::fromCliArgument($raw, $cwd),
            $rawPaths,
        );

        $this->logger->info('Starting dependency graph export', [
            'paths' => array_map(static fn(AbsolutePath $p): string => $p->value(), $paths),
        ]);

        $result = $this->analyzer->analyze($paths, $cwd);
        $this->logger->info('Discovered files', [
            'count' => $result->coverage->discoveredFiles(),
        ]);

        if ($result->coverage->discoveredFiles() === 0) {
            $output->writeln('<error>No files found to analyze</error>');

            return self::FAILURE;
        }

        $this->logger->info('Dependency collection completed', [
            'processed' => $result->coverage->analyzedFilesCount(),
            'skipped' => $result->coverage->skippedFilesCount(),
            'dependencies' => \count($result->graph->getAllDependencies()),
        ]);

        if (!$result->coverage->isComplete()) {
            $this->writeIncompleteAnalysis($output, new IncompleteAnalysisException($result->coverage));

            return self::EXIT_ANALYSIS_INCOMPLETE;
        }

        $this->logger->info('Dependency graph built', [
            'classes' => \count($result->graph->getAllClasses()),
            'namespaces' => \count($result->graph->getAllNamespaces()),
            'dependencies' => \count($result->graph->getAllDependencies()),
        ]);

        // Create exporter with options
        /** @var array<string> $includeNamespaces */
        $includeNamespaces = $input->getOption('namespace');
        /** @var array<string> $excludeNamespaces */
        $excludeNamespaces = $input->getOption('exclude-namespace');

        $format = (string) $input->getOption('format');
        $request = new GraphProjectionRequest(
            format: $format,
            direction: (string) $input->getOption('direction'),
            groupByNamespace: $input->getOption('no-clusters') !== true,
            includeNamespaces: $includeNamespaces !== [] ? $includeNamespaces : null,
            excludeNamespaces: $excludeNamespaces,
        );
        $content = $this->projection->project($result->graph, $request);

        // Output
        /** @var string|null $outputFile */
        $outputFile = $input->getOption('output');

        if ($outputFile !== null) {
            file_put_contents($outputFile, $content);
            $output->writeln(\sprintf('<info>Graph exported to %s</info>', $outputFile));

            if ($format === 'dot') {
                $output->writeln(\sprintf('<comment>Render with: dot -Tpng %s -o graph.png</comment>', $outputFile));
            }
        } else {
            OutputHelper::write($output, $content);
        }

        return self::SUCCESS;
    }

    private function writeIncompleteAnalysis(OutputInterface $output, IncompleteAnalysisException $exception): void
    {
        $diagnostic = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $diagnostic->writeln(\sprintf('<error>%s</error>', $exception->getMessage()));

        foreach ($exception->coverage->failures as $failure) {
            $diagnostic->writeln(\sprintf(
                '<error>[%s] %s: %s</error>',
                $failure->kind->value,
                $failure->path->value(),
                $failure->message,
            ));
        }
    }
}
