<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection;

use LogicException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use Qualimetrix\Analysis\Collection\Metric\CompositeCollector;
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
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\ThresholdDiagnostic;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

/**
 * Processes a single PHP file.
 *
 * Responsible for:
 * - Parsing the file into AST
 * - Collecting metrics and dependencies via CompositeCollector (single AST traversal)
 * - Extracting method/class-level metrics
 * - Memory cleanup after processing
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
            // 1. Parse AST
            $ast = $this->parser->parse($file);

            // 2. Reset collectors & collect metrics + dependencies (single traversal)
            $this->collector->reset();
            $output = $this->collector->collect($file, $ast, $relativePath);

            // 3. Extract declaration-aware callable/class metrics
            $callableMetrics = $this->extractCallableMetrics($relativePath);
            $classMetrics = $this->extractClassMetrics($relativePath);
            $namespaceMetrics = $this->extractNamespaceMetrics();

            // 4. Extract suppression tags from AST nodes
            $suppressions = $this->extractSuppressions($ast);

            // 5. Extract threshold override annotations from AST nodes
            [$thresholdOverrides, $thresholdDiagnostics] = $this->extractThresholdOverrides($ast);

            // 6. Cleanup AST to free memory
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
                suppressions: $suppressions,
                thresholdOverrides: $thresholdOverrides,
                thresholdDiagnostics: $thresholdDiagnostics,
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
            if ($collector instanceof CallableMetricsProviderInterface) {
                foreach ($collector->getCallablesWithMetrics($file) as $callable) {
                    $key = $callable->declarationPath->toCanonical();

                    if (isset($callables[$key])) {
                        $existing = $callables[$key];
                        if ($existing->sourceLine !== null
                            && $callable->sourceLine !== null
                            && $existing->sourceLine !== $callable->sourceLine
                        ) {
                            throw new LogicException(\sprintf(
                                'Callable collectors disagree on source line for %s',
                                $key,
                            ));
                        }

                        $callables[$key] = new \Qualimetrix\Core\Metric\CallableWithMetrics(
                            $existing->declarationPath,
                            $existing->kind,
                            $existing->anonymousSyntax,
                            $existing->lexicalClassContext,
                            $existing->classAggregationOwner,
                            $existing->metrics->merge($callable->metrics),
                            $existing->sourceLine ?? $callable->sourceLine,
                        );
                    } else {
                        $callables[$key] = $callable;
                    }
                }
            }
        }

        return array_values($callables);
    }

    /**
     * Extracts class-level metrics from collectors.
     *
     * @return array<string, array{subject: \Qualimetrix\Core\Symbol\MetricSubject, metrics: MetricBag, line: int}>
     */
    private function extractClassMetrics(\Qualimetrix\Core\Path\RelativePath $file): array
    {
        $classMetrics = [];

        foreach ($this->collector->getCollectors() as $collector) {
            if ($collector instanceof ClassMetricsProviderInterface) {
                foreach ($collector->getClassesWithMetrics($file) as $classWithMetrics) {
                    $key = $classWithMetrics->subject->toCanonical();

                    // Merge metrics if symbol already exists
                    if (isset($classMetrics[$key])) {
                        $classMetrics[$key]['metrics'] = $classMetrics[$key]['metrics']->merge(
                            $classWithMetrics->metrics,
                        );
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
                    $namespaceMetrics[$key]['metrics'] = $namespaceMetrics[$key]['metrics']->merge(
                        $namespaceWithMetrics->metrics,
                    );
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

    /**
     * Extracts suppression tags from all relevant AST nodes.
     *
     * Scans nodes that can have docblocks or regular comments containing `@qmx-ignore`:
     * classes, methods, functions, properties, enum cases, constants, expressions,
     * and any statement preceded by a suppression comment.
     *
     * @param array<Node> $ast Top-level AST statements
     *
     * @return list<Suppression>
     */
    private function extractSuppressions(array $ast): array
    {
        $suppressions = [];

        // Extract file-level suppressions from the first statement's comments
        if ($ast !== []) {
            foreach ($this->suppressionExtractor->extractFileLevelSuppressions($ast[0]) as $suppression) {
                $suppressions[] = $suppression;
            }
        }

        // Find all nodes that can carry suppression comments (docblocks or regular comments)
        $nodeFinder = new NodeFinder();
        $nodesWithSuppressions = $nodeFinder->find($ast, static function (Node $node): bool {
            // Node types that can carry docblock suppressions
            if ($node instanceof Node\Stmt\ClassLike
                || $node instanceof Node\Stmt\ClassMethod
                || $node instanceof Node\Stmt\Function_
                || $node instanceof Node\Stmt\Property
                || $node instanceof Node\Stmt\EnumCase
                || $node instanceof Node\Stmt\ClassConst
                || $node instanceof Node\Stmt\Expression) {
                return true;
            }

            // Any node with a regular comment containing `@qmx-ignore`
            foreach ($node->getComments() as $comment) {
                if (!$comment instanceof \PhpParser\Comment\Doc
                    && str_contains($comment->getText(), '@qmx-ignore')) {
                    return true;
                }
            }

            return false;
        });

        foreach ($nodesWithSuppressions as $node) {
            foreach ($this->suppressionExtractor->extract($node) as $suppression) {
                $suppressions[] = $suppression;
            }
        }

        return $suppressions;
    }

    /**
     * Extracts threshold override annotations from all relevant AST nodes.
     *
     * Scans nodes that can have docblocks: classes, methods, functions.
     *
     * @param array<Node> $ast Top-level AST statements
     *
     * @return array{list<ThresholdOverride>, list<ThresholdDiagnostic>}
     */
    private function extractThresholdOverrides(array $ast): array
    {
        $overrides = [];
        $diagnostics = [];

        $nodeFinder = new NodeFinder();
        $nodesWithDocblocks = $nodeFinder->find($ast, static fn(Node $node): bool => $node instanceof Node\Stmt\ClassLike
                || $node instanceof Node\Stmt\ClassMethod
                || $node instanceof Node\Stmt\Function_);

        foreach ($nodesWithDocblocks as $node) {
            $result = $this->thresholdOverrideExtractor->extractWithDiagnostics($node);

            foreach ($result->overrides as $override) {
                $overrides[] = $override;
            }

            foreach ($result->diagnostics as $diagnostic) {
                $diagnostics[] = $diagnostic;
            }
        }

        return [$overrides, $diagnostics];
    }

}
