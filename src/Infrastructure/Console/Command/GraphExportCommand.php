<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use InvalidArgumentException;
use PhpParser\NodeTraverser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Collection\Dependency\DependencyGraphBuilder;
use Qualimetrix\Analysis\Collection\Dependency\DependencyVisitor;
use Qualimetrix\Analysis\Collection\Dependency\Export\DotExporter;
use Qualimetrix\Analysis\Collection\Dependency\Export\DotExporterOptions;
use Qualimetrix\Analysis\Collection\Dependency\Export\GraphExporterInterface;
use Qualimetrix\Analysis\Collection\Dependency\Export\JsonGraphExporter;
use Qualimetrix\Analysis\Discovery\FileDiscoveryInterface;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Exception\ParseException;
use Qualimetrix\Infrastructure\Console\OutputHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'graph:export',
    description: 'Export dependency graph for visualization (DOT, JSON)',
)]
final class GraphExportCommand extends Command
{
    public function __construct(
        private readonly FileDiscoveryInterface $fileDiscovery,
        private readonly FileParserInterface $fileParser,
        private readonly DependencyVisitor $dependencyVisitor,
        private readonly DependencyGraphBuilder $graphBuilder,
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
        /** @var list<string> $paths */
        $paths = $input->getArgument('paths');

        $this->logger->info('Starting dependency graph export', [
            'paths' => $paths,
        ]);

        // Discover files
        $files = iterator_to_array($this->fileDiscovery->discover($paths), false);
        $this->logger->info('Discovered files', [
            'count' => \count($files),
        ]);

        if ($files === []) {
            $output->writeln('<error>No files found to analyze</error>');

            return self::FAILURE;
        }

        // Collect dependencies from all files
        $filesProcessed = 0;
        $filesSkipped = 0;
        /** @var list<Dependency> $allDependencies */
        $allDependencies = [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->dependencyVisitor);

        foreach ($files as $file) {
            try {
                $ast = $this->fileParser->parse($file);
                $this->dependencyVisitor->setFile($file->getPathname());
                $traverser->traverse($ast);

                foreach ($this->dependencyVisitor->getDependencies() as $dependency) {
                    $allDependencies[] = $dependency;
                }
                $filesProcessed++;
            } catch (ParseException $e) {
                $this->logger->warning('Failed to parse file', [
                    'file' => $file->getPathname(),
                    'error' => $e->getMessage(),
                ]);
                $filesSkipped++;
            }
        }

        $this->logger->info('Dependency collection completed', [
            'processed' => $filesProcessed,
            'skipped' => $filesSkipped,
            'dependencies' => \count($allDependencies),
        ]);

        // Build dependency graph
        $graph = $this->graphBuilder->build($allDependencies);

        $this->logger->info('Dependency graph built', [
            'classes' => \count($graph->getAllClasses()),
            'namespaces' => \count($graph->getAllNamespaces()),
            'dependencies' => \count($graph->getAllDependencies()),
        ]);

        // Create exporter with options
        /** @var array<string> $includeNamespaces */
        $includeNamespaces = $input->getOption('namespace');
        /** @var array<string> $excludeNamespaces */
        $excludeNamespaces = $input->getOption('exclude-namespace');

        $options = new DotExporterOptions(
            direction: (string) $input->getOption('direction'),
            groupByNamespace: !$input->getOption('no-clusters'),
            includeNamespaces: $includeNamespaces !== [] ? $includeNamespaces : null,
            excludeNamespaces: $excludeNamespaces,
        );

        $format = (string) $input->getOption('format');
        $exporter = $this->getExporter($format, $options);

        // Export graph
        $content = $exporter->export($graph);

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

    private function getExporter(string $format, DotExporterOptions $options): GraphExporterInterface
    {
        return match ($format) {
            'dot' => new DotExporter($options),
            'json' => new JsonGraphExporter(
                includeNamespaces: $options->includeNamespaces,
                excludeNamespaces: $options->excludeNamespaces,
            ),
            default => throw new InvalidArgumentException(\sprintf('Unsupported format: %s. Supported formats: dot, json', $format)),
        };
    }
}
