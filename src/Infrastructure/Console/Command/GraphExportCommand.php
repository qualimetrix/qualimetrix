<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DependencyGraphAnalysisResult;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DependencyGraphAnalyzerInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\IncompleteAnalysisException;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Infrastructure\Console\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\AnalysisPreflightProfile;
use Qualimetrix\Infrastructure\Console\ArtifactFile;
use Qualimetrix\Infrastructure\Console\CliSelectorDecoder;
use Qualimetrix\Infrastructure\Console\CommandLineSpelling;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\OutputHelper;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Reporting\GraphProjection\Contract\DependencyGraphProjectionInterface;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphDirection;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphExportFormat;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphProjectionRequest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

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
        private readonly AnalysisPreflight $preflight,
        private readonly ErrorStream $errorStream,
        private readonly RefusalPresenter $refusalPresenter,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly CliSelectorDecoder $selectorDecoder = new CliSelectorDecoder(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to configuration file')
            ->addOption('preset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Apply a named preset or preset file', [])
            ->addOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Directories to exclude', [])
            ->addOption('include-generated', null, InputOption::VALUE_NONE, 'Include generated files')
            ->addOption('include-autoload-dev', null, InputOption::VALUE_NONE, 'Include composer autoload-dev files')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Disable caching')
            ->addOption('workers', 'w', InputOption::VALUE_REQUIRED, 'Number of parallel workers')
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'PHP memory limit')
            ->addArgument(
                'paths',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
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
                \sprintf(
                    'Output format (%s)',
                    implode(', ', array_map(static fn(GraphExportFormat $format): string => $format->value, GraphExportFormat::cases())),
                ),
                GraphExportFormat::Dot->value,
            )
            ->addOption(
                'direction',
                null,
                InputOption::VALUE_REQUIRED,
                \sprintf(
                    'Graph direction (%s)',
                    implode(', ', array_map(static fn(GraphDirection $direction): string => $direction->value, GraphDirection::cases())),
                ),
                GraphDirection::LR->value,
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
                'Include only these namespaces (a value matching no analyzed class is refused with exit code 3)',
            )
            ->addOption(
                'exclude-namespace',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exclude these namespaces',
            )
            ->setHelp(\sprintf('Docs: %s', ProductIdentity::llmsTxtUrl()));
    }

    /**
     * Owns its own catch ladder rather than relying on `Application`'s:
     * `--format=json` renders a JSON
     * document exactly like `check --format=json` does, so a refusal here
     * must arrive as the same `{error, exit_code, position}` envelope — which
     * `Application`'s ladder cannot
     * do, because it never learns this command's `--format`.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // The envelope's format is the one written, read before anything can
        // refuse; a value of another type is refused inside the ladder below.
        $rawFormat = $input->getOption('format');
        $envelopeFormat = \is_string($rawFormat) ? $rawFormat : null;

        try {
            return $this->doExecute($input, $output);
        } catch (ConfigurationRefusal $refusal) {
            return $this->refusalPresenter->refusal($output, $envelopeFormat, $refusal);
        } catch (InvalidArgumentException $failure) {
            // Named secondary signal for code 3: an
            // `InvalidArgumentException` that never became a
            // carrier — e.g. from the path value objects below.
            return $this->refusalPresenter->fallbackRefusal($output, $envelopeFormat, $failure);
        } catch (Throwable $failure) {
            return $this->refusalPresenter->internalError($output, $envelopeFormat, $failure);
        }
    }

    private function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $format = self::resolveFormat(CommandLineSpelling::option($input, 'format') ?? '');
        $direction = self::resolveDirection(CommandLineSpelling::option($input, 'direction') ?? '');
        $outputFile = self::prepareOutputFile($input);

        $prepared = $this->preflight->resolve($input, $output, AnalysisPreflightProfile::graph());
        $request = $this->buildProjectionRequest($input, $format, $direction);

        $this->logger->info('Starting dependency graph export', [
            'paths' => array_map(static fn(AbsolutePath $p): string => $p->value(), $prepared->runConfiguration->paths),
        ]);

        $result = $this->analyzeDependencyGraph($prepared->runConfiguration);
        $this->logger->info('Discovered files', [
            'count' => $result->coverage->discoveredFiles(),
        ]);

        $coverageExit = $this->resolveCoverageExit($result, $output);
        if ($coverageExit !== null) {
            return $coverageExit;
        }

        $this->logGraphBuilt($result);
        $this->assertIncludeNamespacesBind($result, $request);
        $content = $this->projection->project($result->graph, $request);
        self::publishGraph($output, $outputFile, $content, $format);

        return self::SUCCESS;
    }

    private static function prepareOutputFile(InputInterface $input): ?ArtifactFile
    {
        $outputPath = CommandLineSpelling::option($input, 'output');
        $outputFile = $outputPath === null ? null : new ArtifactFile($outputPath, '--output');
        $outputFile?->refuseUnwritable();

        return $outputFile;
    }

    private function resolveCoverageExit(DependencyGraphAnalysisResult $result, OutputInterface $output): ?int
    {
        if (!$result->coverage->isComplete()) {
            $this->writeIncompleteAnalysis($output, new IncompleteAnalysisException($result->coverage));

            return self::EXIT_ANALYSIS_INCOMPLETE;
        }

        if ($result->coverage->discoveredFiles() === 0 && !$result->coverage->isIntentionallyEmpty()) {
            // An analysis outcome, not an input refusal.
            $output->writeln('<error>No files found to analyze</error>');

            return self::FAILURE;
        }

        if ($result->coverage->isIntentionallyEmpty()) {
            $coverage = $result->coverage;
            $this->errorStream->write($output, \Qualimetrix\Reporting\Formatter\CoverageNarrator::describe(new \Qualimetrix\Reporting\ReportCoverage(
                $coverage->discoveredFiles(),
                $coverage->analyzedFilesCount(),
                $coverage->generatedExcludedFilesCount(),
                $coverage->failedFilesCount(),
                excluded: $coverage->excludedCount(),
            )));
        }

        return null;
    }

    private function logGraphBuilt(DependencyGraphAnalysisResult $result): void
    {
        $this->logger->info('Dependency collection completed', [
            'processed' => $result->coverage->analyzedFilesCount(),
            'skipped' => $result->coverage->skippedFilesCount(),
            'dependencies' => \count($result->graph->getAllDependencies()),
        ]);

        $this->logger->info('Dependency graph built', [
            'classes' => \count($result->graph->getAllClasses()),
            'namespaces' => \count($result->graph->getAllNamespaces()),
            'dependencies' => \count($result->graph->getAllDependencies()),
        ]);
    }

    private static function publishGraph(OutputInterface $output, ?ArtifactFile $outputFile, string $content, GraphExportFormat $format): void
    {
        if ($outputFile !== null) {
            self::writeToFile($output, $outputFile, $content, $format);
        } else {
            OutputHelper::write($output, $content);
        }
    }

    /**
     * An include namespace matching nothing renders a graph the caller cannot
     * tell apart from a genuinely empty one, so it is refused rather than
     * drawn. The
     * excluding sibling `--exclude-namespace` deliberately keeps its silence:
     * a miss there leaves the graph exactly as it would have been, and the
     * caller loses nothing.
     *
     * @throws ConfigurationRefusal
     */
    private function assertIncludeNamespacesBind(DependencyGraphAnalysisResult $result, GraphProjectionRequest $request): void
    {
        $unbound = $this->projection->unboundIncludeNamespaces($result->graph, $request);
        if ($unbound === []) {
            return;
        }

        throw ConfigurationRefusal::aboutCommandLineInput(
            '--namespace',
            \sprintf(
                'No analyzed class belongs to %s: %s.',
                \count($unbound) === 1 ? 'namespace' : 'namespaces',
                implode(', ', array_map(static fn(string $namespace): string => '"' . $namespace . '"', $unbound)),
            ),
        );
    }

    /** @throws ConfigurationRefusal */
    private static function resolveFormat(string $rawFormat): GraphExportFormat
    {
        $format = GraphExportFormat::tryFrom($rawFormat);
        if ($format === null) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--format',
                \sprintf(
                    'Unknown format "%s". Supported formats: %s.',
                    $rawFormat,
                    implode(', ', array_map(static fn(GraphExportFormat $f): string => $f->value, GraphExportFormat::cases())),
                ),
            );
        }

        return $format;
    }

    /** @throws ConfigurationRefusal */
    private static function resolveDirection(string $rawDirection): GraphDirection
    {
        $direction = GraphDirection::tryFrom($rawDirection);
        if ($direction === null) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--direction',
                \sprintf(
                    'Unknown direction "%s". Supported directions: %s.',
                    $rawDirection,
                    implode(', ', array_map(static fn(GraphDirection $d): string => $d->value, GraphDirection::cases())),
                ),
            );
        }

        return $direction;
    }

    private function buildProjectionRequest(InputInterface $input, GraphExportFormat $format, GraphDirection $direction): GraphProjectionRequest
    {
        $includeNamespaces = CommandLineSpelling::options($input, 'namespace');
        $excludeNamespaces = CommandLineSpelling::options($input, 'exclude-namespace');

        return new GraphProjectionRequest(
            format: $format,
            direction: $direction,
            groupByNamespace: $input->getOption('no-clusters') !== true,
            includeNamespaces: $includeNamespaces !== [] ? array_values(array_map(
                fn(string $value): NamespacePattern => $this->selectorDecoder->decodeNamespace($value, '--namespace'),
                $includeNamespaces,
            )) : null,
            excludeNamespaces: array_values(array_map(
                fn(string $value): NamespacePattern => $this->selectorDecoder->decodeNamespace($value, '--exclude-namespace'),
                $excludeNamespaces,
            )),
        );
    }

    /** @throws ConfigurationRefusal */
    private static function writeToFile(OutputInterface $output, ArtifactFile $outputFile, string $content, GraphExportFormat $format): void
    {
        $outputFile->write($content);

        $output->writeln(\sprintf('<info>Graph exported to %s</info>', $outputFile->path));

        if ($format === GraphExportFormat::Dot) {
            $output->writeln(\sprintf('<comment>Render with: dot -Tpng %s -o graph.png</comment>', $outputFile->path));
        }
    }

    private function analyzeDependencyGraph(
        \Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration $configuration,
    ): DependencyGraphAnalysisResult {
        return $this->analyzer->analyze($configuration);
    }

    private function writeIncompleteAnalysis(OutputInterface $output, IncompleteAnalysisException $exception): void
    {
        $diagnostic = $this->errorStream->writer($output);
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
