<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection;

use LogicException;
use Qualimetrix\Analysis\Collection\Declaration\DeclarationBindings;
use Qualimetrix\Analysis\Collection\Metric\CompositeCollector;
use Qualimetrix\Analysis\Collection\SourceControl\SourceControls;
use Qualimetrix\Baseline\Suppression\SuppressionExtractor;
use Qualimetrix\Baseline\Suppression\ThresholdOverrideExtractor;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Exception\ParseException;
use Qualimetrix\Core\Metric\CallableMetricsProviderInterface;
use Qualimetrix\Core\Metric\ClassMetricsProviderInterface;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\NamespaceMetricProviderInterface;
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
        private readonly CompositeCollector $collector,
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
            $output = $this->collector->collect($file, $ast, $relativePath);

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
                fileBag: $output->metrics,
                callableMetrics: $callableMetrics,
                classMetrics: $classMetrics,
                namespaceMetrics: $namespaceMetrics,
                dependencies: $output->dependencies,
                suppressions: $controls->suppressions,
                thresholdOverrides: $controls->thresholdOverrides,
                thresholdDiagnostics: $controls->thresholdDiagnostics,
            );
        } catch (ParseException $e) {
            return FileProcessingResult::failure(
                filePath: $relativePath,
                error: $e->getMessage(),
                kind: FileProcessingFailureKind::Parse,
            );
        }
    }

    /**
     * Extracts callable-level metrics from collectors without collapsing
     * distinct declarations that share a logical FQN.
     *
     * @return list<\Qualimetrix\Core\Metric\CallableWithMetrics>
     */
    private function extractCallableMetrics(\Qualimetrix\Core\Path\RelativePath $file): array
    {
        /** @var array<string, \Qualimetrix\Core\Metric\CallableWithMetrics> $callables */
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
        \Qualimetrix\Core\Metric\CallableWithMetrics $existing,
        \Qualimetrix\Core\Metric\CallableWithMetrics $callable,
        string $key,
    ): \Qualimetrix\Core\Metric\CallableWithMetrics {
        if ($existing->sourceLine !== null
            && $callable->sourceLine !== null
            && $existing->sourceLine !== $callable->sourceLine
        ) {
            throw new LogicException(\sprintf(
                'Callable collectors disagree on source line for %s',
                $key,
            ));
        }

        return new \Qualimetrix\Core\Metric\CallableWithMetrics(
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
