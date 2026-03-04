<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection;

use AiMessDetector\Analysis\Collection\Metric\CompositeCollector;
use AiMessDetector\Core\Ast\FileParserInterface;
use AiMessDetector\Core\Exception\ParseException;
use AiMessDetector\Core\Metric\ClassMetricsProviderInterface;
use AiMessDetector\Core\Metric\MethodMetricsProviderInterface;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Violation\SymbolPath;
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

            // 4. Cleanup AST to free memory
            unset($ast);
            if (gc_enabled()) {
                gc_collect_cycles();
            }

            return FileProcessingResult::success(
                filePath: $file->getPathname(),
                fileBag: $output->metrics,
                methodMetrics: $methodMetrics,
                classMetrics: $classMetrics,
                dependencies: $output->dependencies,
            );
        } catch (ParseException $e) {
            return FileProcessingResult::failure(
                filePath: $file->getPathname(),
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
