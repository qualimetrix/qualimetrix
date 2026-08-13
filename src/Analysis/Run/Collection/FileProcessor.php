<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Collection;

use LogicException;
use Qualimetrix\Analysis\Collection\SourceControl\SourceControls;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableMetricsProviderInterface;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectionOutput;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileMeasurementCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceMetricProviderInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\Declaration\DeclarationBindings;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingFailureKind;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\SuccessfulFileProcessing;
use Qualimetrix\Baseline\Suppression\SuppressionExtractor;
use Qualimetrix\Baseline\Suppression\ThresholdOverrideExtractor;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Exception\ParseException;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

/**
 * Processes a source file while keeping parsing, collection, and
 * collection-wire assembly. Declaration binding and source
 * control extraction are immutable downstream results: they receive the AST
 * and collected metrics but do not invoke collectors or alter
 * the FileProcessingResult transport. Parse failures remain terminal
 * results, so sequential and parallel callers preserve the contract.
 */
final class FileProcessor implements FileProcessorInterface
{
    private ?AbsolutePath $projectRoot = null;

    public function __construct(
        private readonly FileParserInterface $parser,
        private readonly FileMeasurementCollectorInterface $collector,
        private readonly SuppressionExtractor $suppressionExtractor = new SuppressionExtractor(),
        private readonly ThresholdOverrideExtractor $thresholdOverrideExtractor = new ThresholdOverrideExtractor(),
    ) {}

    /**
     * Sets the project root used to relativize file paths. The orchestrator
     * (sequential side) and {@see WorkerBootstrap} (parallel side) both call
     * this before invoking {@see process()} so projectRoot isn't carried as a
     * cross-namespace constructor dependency.
     */
    public function setProjectRoot(AbsolutePath $projectRoot): void
    {
        $this->projectRoot = $projectRoot;
    }

    public function process(SplFileInfo $file): FileProcessingResult
    {
        if ($this->projectRoot === null) {
            throw new LogicException('projectRoot must be set via setProjectRoot() before process()');
        }

        $relativePath = PathFactory::bestEffortRelative($file->getPathname(), $this->projectRoot);

        try {
            $ast = $this->parser->parse($file);
            $this->collector->reset();
            $output = $this->collectMeasurements($file, $ast, $relativePath);

            $callableMetrics = $this->extractCallableMetrics($relativePath);
            $classMetrics = $this->extractClassMetrics($relativePath);
            $namespaceMetrics = $this->extractNamespaceMetrics();
            $bindings = DeclarationBindings::from($ast, $relativePath, $callableMetrics, $classMetrics);
            $controls = SourceControls::extract(
                $ast,
                $bindings,
                $this->suppressionExtractor,
                $this->thresholdOverrideExtractor,
            );

            unset($ast);
            if (gc_enabled()) {
                gc_collect_cycles();
            }

            return FileProcessingResult::success(
                filePath: $relativePath,
                payload: new SuccessfulFileProcessing(
                    fileBag: $output->metrics,
                    callableMetrics: $callableMetrics,
                    classMetrics: $classMetrics,
                    namespaceMetrics: $namespaceMetrics,
                    dependencies: $output->dependencies,
                    suppressions: $controls->suppressions,
                    thresholdOverrides: $controls->thresholdOverrides,
                    thresholdDiagnostics: $controls->thresholdDiagnostics,
                ),
            );
        } catch (ParseException $e) {
            return FileProcessingResult::failure(
                filePath: $relativePath,
                error: $e->getMessage(),
                kind: FileProcessingFailureKind::Parse,
            );
        }
    }

    /** @param array<\PhpParser\Node> $ast */
    private function collectMeasurements(SplFileInfo $file, array $ast, \Qualimetrix\Core\Path\RelativePath $filePath): CollectionOutput
    {
        return $this->collector->collect($file, $ast, $filePath);
    }

    /**
     * Extracts callable-level metrics from collectors without collapsing
     * distinct declarations that share a logical FQN.
     *
     * @return list<\Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics>
     */
    private function extractCallableMetrics(\Qualimetrix\Core\Path\RelativePath $file): array
    {
        /** @var array<string, \Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics> $callables */
        $callables = [];

        foreach ($this->collector->getCollectors() as $collector) {
            if (!$collector instanceof CallableMetricsProviderInterface) {
                continue;
            }

            foreach ($collector->getCallablesWithMetrics($file) as $callable) {
                $key = $callable->declarationPath->toCanonical();
                $callables[$key] = isset($callables[$key])
                    ? $this->mergeCallableMetrics($callables[$key], $callable, $key)
                    : $callable;
            }
        }

        return array_values($callables);
    }

    private function mergeCallableMetrics(
        \Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics $existing,
        \Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics $callable,
        string $key,
    ): \Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics {
        if ($existing->sourceLine !== null
            && $callable->sourceLine !== null
            && $existing->sourceLine !== $callable->sourceLine
        ) {
            throw new LogicException(\sprintf(
                'Callable collectors disagree on source line for %s',
                $key,
            ));
        }

        return new \Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics(
            $existing->declarationPath,
            $existing->kind,
            $existing->anonymousSyntax,
            $existing->lexicalClassContext,
            $existing->classAggregationOwner,
            $existing->metrics->merge($callable->metrics),
            $existing->sourceLine ?? $callable->sourceLine,
        );
    }

    /**
     * @return array<string, array{subject: \Qualimetrix\Core\Symbol\MetricSubject, metrics: MetricBag, line: int}>
     */
    private function extractClassMetrics(\Qualimetrix\Core\Path\RelativePath $file): array
    {
        $classMetrics = [];

        foreach ($this->collector->getCollectors() as $collector) {
            if ($collector instanceof ClassMetricsProviderInterface) {
                foreach ($collector->getClassesWithMetrics($file) as $classWithMetrics) {
                    $key = $classWithMetrics->subject->toCanonical();
                    if (isset($classMetrics[$key])) {
                        $classMetrics[$key]['metrics'] = $classMetrics[$key]['metrics']->merge($classWithMetrics->metrics);
                    } else {
                        $classMetrics[$key] = [
                            'subject' => $classWithMetrics->subject,
                            'metrics' => $classWithMetrics->metrics,
                            'line' => $classWithMetrics->line,
                        ];
                    }
                }
            }
        }

        return $classMetrics;
    }

    /**
     * @return array<string, array{symbolPath: SymbolPath, metrics: MetricBag, line: int}>
     */
    private function extractNamespaceMetrics(): array
    {
        $namespaceMetrics = [];

        foreach ($this->collector->getCollectors() as $collector) {
            if (!$collector instanceof NamespaceMetricProviderInterface) {
                continue;
            }

            foreach ($collector->getNamespacesWithMetrics() as $namespaceWithMetrics) {
                $symbolPath = $namespaceWithMetrics->getSymbolPath();
                $key = $symbolPath->toCanonical();
                if (isset($namespaceMetrics[$key])) {
                    $namespaceMetrics[$key]['metrics'] = $namespaceMetrics[$key]['metrics']->merge($namespaceWithMetrics->metrics);
                } else {
                    $namespaceMetrics[$key] = [
                        'symbolPath' => $symbolPath,
                        'metrics' => $namespaceWithMetrics->metrics,
                        'line' => $namespaceWithMetrics->line,
                    ];
                }
            }
        }

        return $namespaceMetrics;
    }
}
