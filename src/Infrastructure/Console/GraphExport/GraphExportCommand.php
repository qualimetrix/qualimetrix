<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\GraphExport;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Run\Contract\Coverage\IncompleteAnalysisException;
use Qualimetrix\Analysis\Run\Contract\DependencyGraph\DependencyGraphAnalysisResult;
use Qualimetrix\Analysis\Run\Contract\DependencyGraph\DependencyGraphAnalyzerInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Console\Invocation\CommandLineSpelling;
use Qualimetrix\Infrastructure\Console\Invocation\Configuration\Diagnostics\AnalysisPreflightProfile;
use Qualimetrix\Infrastructure\Console\RunSetup\Input\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargetSession;
use Qualimetrix\Infrastructure\Console\Terminal\Diagnostics\ErrorStream;
use Qualimetrix\Infrastructure\Console\Terminal\ReportPublication\OutputHelper;
use Qualimetrix\Reporting\GraphProjection\Contract\DependencyGraphProjectionInterface;
use Qualimetrix\Reporting\GraphProjection\Contract\GraphExportFormat;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'graph:export',
    description: 'Export dependency graph for visualization (DOT, JSON)',
)]
/**
 * @qmx-threshold coupling.instability 0.920001 -- This subject implements an owner-declared outward protocol with intentionally few incoming readers; the finite boundary preserves visibility of additional dependencies.
 * @qmx-threshold coupling.cbo 26 -- Graph input resolution is separate; this delivery command still coordinates one graph export, its format and diagnostic outcome.
 */
final class GraphExportCommand extends Command
{
    private const int EXIT_ANALYSIS_INCOMPLETE = 4;

    public function __construct(
        private readonly DependencyGraphAnalyzerInterface $analyzer,
        private readonly DependencyGraphProjectionInterface $projection,
        private readonly AnalysisPreflight $preflight,
        private readonly ErrorStream $errorStream,
        private readonly RunTargetSession $runTargetSession,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly GraphExportInput $graphInput = new GraphExportInput(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->graphInput->configure($this);
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

        return $this->runTargetSession->run($output, $envelopeFormat, fn(): int => $this->doExecute($input, $output));
    }

    private function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $format = GraphExportInput::format($input);
        $direction = GraphExportInput::direction($input);
        $outputFile = CommandLineSpelling::option($input, 'output');
        if ($outputFile !== null) {
            $this->runTargetSession->targets()->judge('--output', $outputFile);
        } else {
            $this->runTargetSession->targets()->reportOnStandardOutput();
        }

        $prepared = $this->preflight->resolve($input, $output, AnalysisPreflightProfile::graph());
        $request = $this->graphInput->projectionRequest($input, $format, $direction);
        foreach ($this->runTargetSession->targets()->exposureWarnings() as $warning) {
            $this->errorStream->write($output, '<comment>Warning: '
                . \Symfony\Component\Console\Formatter\OutputFormatter::escape($warning) . '</comment>');
        }

        $this->runTargetSession->targets()->claim($prepared->inputFileReadAs);

        $this->logger->info('Starting dependency graph export', [
            'paths' => array_map(static fn(AbsolutePath $p): string => $p->value(), $prepared->runConfiguration->paths),
        ]);

        $result = $this->analyzeDependencyGraph($prepared->runConfiguration);
        foreach ($result->mixedSpellings as $mixed) {
            $this->logger->warning(\sprintf(
                'mixed spelling: %s → %s',
                implode(', ', $mixed->spellings),
                $mixed->canonical,
            ));
        }
        $this->logger->info('Discovered files', [
            'count' => $result->coverage->discoveredFiles(),
        ]);

        $coverageExit = $this->resolveCoverageExit($result, $output);
        if ($coverageExit !== null) {
            return $coverageExit;
        }

        $this->logGraphBuilt($result);
        $this->graphInput->assertFiltersBind($this->projection, $result, $request);
        $content = $this->projection->project($result->graph, $request);
        $this->publishGraph($output, $outputFile, $content, $format);
        $this->runTargetSession->markOutputPublished();

        return self::SUCCESS;
    }

    private function resolveCoverageExit(DependencyGraphAnalysisResult $result, OutputInterface $output): ?int
    {
        if (!$result->coverage->isComplete()) {
            $this->writeIncompleteAnalysis($output, new IncompleteAnalysisException($result->coverage));

            return self::EXIT_ANALYSIS_INCOMPLETE;
        }

        if ($result->coverage->discoveredFiles() === 0 || $result->coverage->isIntentionallyEmpty()) {
            $coverage = $result->coverage;
            $this->errorStream->write($output, \Qualimetrix\Reporting\Formatter\Text\CoverageNarrator::describe(new \Qualimetrix\Reporting\Coverage\ReportCoverage(
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

    private function publishGraph(OutputInterface $output, ?string $outputFile, string $content, GraphExportFormat $format): void
    {
        if ($outputFile !== null) {
            $this->writeToFile($output, $outputFile, $content, $format);
        } else {
            OutputHelper::write($output, $content);
        }
    }

    /** @throws ConfigurationRefusal */
    private function writeToFile(OutputInterface $output, string $outputFile, string $content, GraphExportFormat $format): void
    {
        $this->runTargetSession->targets()->write('--output', $content);

        $this->errorStream->write($output, \sprintf('<info>Graph exported to %s</info>', $outputFile));

        if ($format === GraphExportFormat::Dot) {
            $this->errorStream->write($output, \sprintf('<comment>Render with: dot -Tpng %s -o graph.png</comment>', $outputFile));
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
