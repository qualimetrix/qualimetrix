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
use Qualimetrix\Core\Path\PathFactory;
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
        private readonly ErrorStream $errorStream,
        private readonly RefusalPresenter $refusalPresenter,
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
                'Include only these namespaces',
            )
            ->addOption(
                'exclude-namespace',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exclude these namespaces',
            );
    }

    /**
     * Owns its own catch ladder rather than relying on `Application`'s
     * (`01-refusal-exit-ladder.md` §2.4): `--format=json` renders a JSON
     * document exactly like `check --format=json` does, so a refusal here
     * must arrive as the same `{error, exit_code}` envelope
     * (`01-refusal-envelope.md` §2.1) — which `Application`'s ladder cannot
     * do, because it never learns this command's `--format`.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $rawFormat */
        $rawFormat = $input->getOption('format');

        try {
            return $this->doExecute($input, $output, $rawFormat);
        } catch (ConfigurationRefusal $refusal) {
            return $this->refusalPresenter->refusal($output, $rawFormat, $refusal);
        } catch (InvalidArgumentException $failure) {
            // Named secondary signal for code 3 (`01-refusal-exit-ladder.md`
            // §2.6): an `InvalidArgumentException` that never became a
            // carrier — e.g. from the path value objects below.
            return $this->refusalPresenter->fallbackRefusal($output, $rawFormat, $failure);
        } catch (Throwable $failure) {
            return $this->refusalPresenter->internalError($output, $rawFormat, $failure);
        }
    }

    private function doExecute(InputInterface $input, OutputInterface $output, string $rawFormat): int
    {
        // Every check in this method runs before `analyzeDependencyGraph()`
        // — the round's `--direction`/`--format`/`--output` refusals must
        // not pay for a Discovery+Collection run that their own answer
        // throws away (`01-refusal-packages.md`, P01-5 DoD: a bogus
        // `--direction`/`--format` reaches the analyzer zero times).
        $format = self::resolveFormat($rawFormat);

        /** @var string $rawDirection */
        $rawDirection = $input->getOption('direction');
        $direction = self::resolveDirection($rawDirection);

        /** @var string|null $outputFile */
        $outputFile = $input->getOption('output');
        if ($outputFile !== null) {
            self::assertWritable($outputFile);
        }

        $cwd = AbsolutePath::fromString((string) getcwd());
        $paths = self::resolvePaths($input, $cwd);

        $this->logger->info('Starting dependency graph export', [
            'paths' => array_map(static fn(AbsolutePath $p): string => $p->value(), $paths),
        ]);

        $result = $this->analyzeDependencyGraph($paths, $cwd);
        $this->logger->info('Discovered files', [
            'count' => $result->coverage->discoveredFiles(),
        ]);

        if ($result->coverage->discoveredFiles() === 0) {
            // An analysis outcome, not an input refusal — the round does not
            // touch it (`01-refusal-verdicts.md` §5.7).
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

        $request = self::buildProjectionRequest($input, $format, $direction);
        $content = $this->projection->project($result->graph, $request);

        if ($outputFile !== null) {
            self::writeToFile($output, $outputFile, $content, $format);
        } else {
            OutputHelper::write($output, $content);
        }

        return self::SUCCESS;
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

    /** @return list<AbsolutePath> */
    private static function resolvePaths(InputInterface $input, AbsolutePath $cwd): array
    {
        /** @var list<string> $rawPaths */
        $rawPaths = $input->getArgument('paths');

        return array_map(
            static fn(string $raw): AbsolutePath => PathFactory::fromCliArgument($raw, $cwd),
            $rawPaths,
        );
    }

    private static function buildProjectionRequest(InputInterface $input, GraphExportFormat $format, GraphDirection $direction): GraphProjectionRequest
    {
        /** @var array<string> $includeNamespaces */
        $includeNamespaces = $input->getOption('namespace');
        /** @var array<string> $excludeNamespaces */
        $excludeNamespaces = $input->getOption('exclude-namespace');

        return new GraphProjectionRequest(
            format: $format,
            direction: $direction,
            groupByNamespace: $input->getOption('no-clusters') !== true,
            includeNamespaces: $includeNamespaces !== [] ? $includeNamespaces : null,
            excludeNamespaces: $excludeNamespaces,
        );
    }

    /** @throws ConfigurationRefusal */
    private static function writeToFile(OutputInterface $output, string $outputFile, string $content, GraphExportFormat $format): void
    {
        // The pre-check in doExecute() catches most cases before analysis
        // runs; this catches the race (writability changed since) and a
        // `rename()`/write failure `@`-silenced before the round
        // (`01-refusal-verdicts.md` §5.2, the `check --output` sibling
        // of this check at §5.4).
        if (@file_put_contents($outputFile, $content) === false) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--output',
                \sprintf('Failed to write output to %s', $outputFile),
            );
        }

        $output->writeln(\sprintf('<info>Graph exported to %s</info>', $outputFile));

        if ($format === GraphExportFormat::Dot) {
            $output->writeln(\sprintf('<comment>Render with: dot -Tpng %s -o graph.png</comment>', $outputFile));
        }
    }

    /**
     * Checked before analysis so a doomed `--output` fails fast rather than
     * after a full Discovery+Collection run.
     */
    private static function assertWritable(string $outputFile): void
    {
        if (file_exists($outputFile)) {
            if (!is_writable($outputFile)) {
                throw ConfigurationRefusal::aboutCommandLineInput(
                    '--output',
                    \sprintf('Output path "%s" is not writable', $outputFile),
                );
            }

            return;
        }

        $directory = \dirname($outputFile);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--output',
                \sprintf('Output path "%s" is not writable', $outputFile),
            );
        }
    }

    /** @param list<AbsolutePath> $paths */
    private function analyzeDependencyGraph(array $paths, AbsolutePath $projectRoot): DependencyGraphAnalysisResult
    {
        return $this->analyzer->analyze($paths, $projectRoot);
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
