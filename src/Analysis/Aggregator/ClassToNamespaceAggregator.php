<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Aggregator;

use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;

final class ClassToNamespaceAggregator implements AggregationPhaseInterface
{
    /**
     * @param list<MetricDefinition> $definitions
     */
    public function aggregate(InMemoryMetricRepository $repository, array $definitions): void
    {
        $profiler = ProfilerHolder::get();

        $namespaceDefinitions = array_values(array_filter(
            $definitions,
            static fn(MetricDefinition $d): bool => $d->hasAggregationsForLevel(SymbolLevel::Namespace_),
        ));

        if ($namespaceDefinitions === []) {
            return;
        }

        $profiler->start('aggregation.to_namespaces.build_map', 'aggregation');
        $fileToNamespace = $this->buildFileToNamespaceMap($repository);
        $namespaceToFileSymbols = $this->buildNamespaceToFileSymbolsMap($repository, $fileToNamespace);
        $profiler->stop('aggregation.to_namespaces.build_map');

        $profiler->start('aggregation.to_namespaces.process', 'aggregation');
        foreach ($repository->getNamespaces() as $namespace) {
            $symbolInfos = $repository->forNamespace($namespace);

            if ($symbolInfos === []) {
                continue;
            }

            $fileSymbols = $namespaceToFileSymbols[$namespace] ?? [];

            $metricValues = AggregationHelper::collectNamespaceMetricValues(
                $repository,
                $symbolInfos,
                $fileSymbols,
                $namespaceDefinitions,
            );

            $namespaceBag = AggregationHelper::applyAggregations($metricValues, $namespaceDefinitions, SymbolLevel::Namespace_);
            $namespaceBag = AggregationHelper::addSymbolCounts($namespaceBag, $symbolInfos);

            $firstFile = $symbolInfos[0]->file;
            $namespacePath = SymbolPath::forNamespace($namespace);
            $repository->add($namespacePath, $namespaceBag, $firstFile, null);
        }
        $profiler->stop('aggregation.to_namespaces.process');
    }

    /**
     * Builds a map of file path to namespace based on class/method symbols.
     *
     * @return array<string, string> file path => namespace
     */
    private function buildFileToNamespaceMap(InMemoryMetricRepository $repository): array
    {
        $map = [];

        foreach ($repository->all(SymbolType::Class_) as $classInfo) {
            $namespace = $classInfo->symbolPath->namespace;

            if ($namespace !== null) {
                $map[$classInfo->file] = $namespace;
            }
        }

        foreach ($repository->all(SymbolType::Method) as $methodInfo) {
            $namespace = $methodInfo->symbolPath->namespace;

            if ($namespace !== null && !isset($map[$methodInfo->file])) {
                $map[$methodInfo->file] = $namespace;
            }
        }

        return $map;
    }

    /**
     * Builds a map of namespace to list of File symbols.
     *
     * @param array<string, string> $fileToNamespace
     *
     * @return array<string, list<SymbolInfo>>
     */
    private function buildNamespaceToFileSymbolsMap(
        InMemoryMetricRepository $repository,
        array $fileToNamespace,
    ): array {
        $map = [];

        foreach ($repository->all(SymbolType::File) as $fileInfo) {
            $filePath = $fileInfo->file;

            if (isset($fileToNamespace[$filePath])) {
                $namespace = $fileToNamespace[$filePath];
                $map[$namespace][] = $fileInfo;
            }
        }

        return $map;
    }
}
