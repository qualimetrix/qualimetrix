<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Qualimetrix\Analysis\Collection\Metric\CompositeCollector;
use Qualimetrix\Baseline\Suppression\SuppressionExtractor;
use Qualimetrix\Baseline\Suppression\ThresholdOverrideExtractor;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Exception\ParseException;
use Qualimetrix\Core\Metric\ClassMetricsProviderInterface;
use Qualimetrix\Core\Metric\MethodMetricsProviderInterface;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\ThresholdDiagnostic;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Util\PathNormalizer;
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
    public function __construct(
        private readonly FileParserInterface $parser,
        private readonly CompositeCollector $collector,
        private readonly SuppressionExtractor $suppressionExtractor = new SuppressionExtractor(),
        private readonly ThresholdOverrideExtractor $thresholdOverrideExtractor = new ThresholdOverrideExtractor(),
    ) {}

    public function process(SplFileInfo $file): FileProcessingResult
    {
        try {
            // 1. Parse AST
            $ast = $this->parser->parse($file);

            // 2. Reset collectors & collect metrics + dependencies (single traversal)
            $this->collector->reset();
            $output = $this->collector->collect($file, $ast);

            // 3. Extract method/class metrics
            $methodMetrics = $this->extractMethodMetrics();
            $classMetrics = $this->extractClassMetrics();

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
                filePath: PathNormalizer::relativize($file->getPathname()),
                fileBag: $output->metrics,
                methodMetrics: $methodMetrics,
                classMetrics: $classMetrics,
                dependencies: $output->dependencies,
                suppressions: $suppressions,
                thresholdOverrides: $thresholdOverrides,
                thresholdDiagnostics: $thresholdDiagnostics,
            );
        } catch (ParseException $e) {
            return FileProcessingResult::failure(
                filePath: PathNormalizer::relativize($file->getPathname()),
                error: $e->getMessage(),
            );
        }
    }

    /**
     * Extracts method-level metrics from collectors.
     *
     * @return array<string, array{symbolPath: SymbolPath, metrics: MetricBag, line: int}>
     */
    private function extractMethodMetrics(): array
    {
        $methodMetrics = [];

        foreach ($this->collector->getCollectors() as $collector) {
            if ($collector instanceof MethodMetricsProviderInterface) {
                foreach ($collector->getMethodsWithMetrics() as $methodWithMetrics) {
                    $symbolPath = $methodWithMetrics->getSymbolPath();

                    // Skip closures and other symbols without stable identity
                    if ($symbolPath === null) {
                        continue;
                    }

                    $key = $this->symbolPathToKey($symbolPath);

                    // Merge metrics if symbol already exists
                    if (isset($methodMetrics[$key])) {
                        $methodMetrics[$key]['metrics'] = $methodMetrics[$key]['metrics']->merge(
                            $methodWithMetrics->metrics,
                        );
                    } else {
                        $methodMetrics[$key] = [
                            'symbolPath' => $symbolPath,
                            'metrics' => $methodWithMetrics->metrics,
                            'line' => $methodWithMetrics->line,
                        ];
                    }
                }
            }
        }

        return $methodMetrics;
    }

    /**
     * Extracts class-level metrics from collectors.
     *
     * @return array<string, array{symbolPath: SymbolPath, metrics: MetricBag, line: int}>
     */
    private function extractClassMetrics(): array
    {
        $classMetrics = [];

        foreach ($this->collector->getCollectors() as $collector) {
            if ($collector instanceof ClassMetricsProviderInterface) {
                foreach ($collector->getClassesWithMetrics() as $classWithMetrics) {
                    $key = $this->symbolPathToKey($classWithMetrics->getSymbolPath());

                    // Merge metrics if symbol already exists
                    if (isset($classMetrics[$key])) {
                        $classMetrics[$key]['metrics'] = $classMetrics[$key]['metrics']->merge(
                            $classWithMetrics->metrics,
                        );
                    } else {
                        $classMetrics[$key] = [
                            'symbolPath' => $classWithMetrics->getSymbolPath(),
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
     * Extracts suppression tags from all relevant AST nodes.
     *
     * Scans nodes that can have docblocks or regular comments containing @qmx-ignore:
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

            // Any node with a regular comment containing @qmx-ignore
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

    /**
     * Converts SymbolPath to unique string key.
     */
    private function symbolPathToKey(SymbolPath $symbolPath): string
    {
        $parts = [];

        if ($symbolPath->namespace !== null) {
            $parts[] = $symbolPath->namespace;
        }

        if ($symbolPath->type !== null) {
            $parts[] = $symbolPath->type;
        }

        if ($symbolPath->member !== null) {
            $parts[] = $symbolPath->member;
        }

        return implode('::', $parts);
    }
}
