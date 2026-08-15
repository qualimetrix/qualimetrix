<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentInspectorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\GeneratedFileFilterInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use SplFileInfo;

/** Resolves a debug layer assignment from the same collected project state as analysis. */
final readonly class LayerAssignmentResolver
{
    public function __construct(
        private CollectionOrchestratorInterface $collectionOrchestrator,
        private DependencyGraphBuilderInterface $graphBuilder,
        private LayerAssignmentInspectorInterface $layerAssignmentInspector,
        private MetricRepositoryFactoryInterface $repositoryFactory,
        private FileDiscoveryFactoryInterface $fileDiscoveryFactory,
        private GeneratedFileFilterInterface $generatedFileFilter,
    ) {}

    /**
     * @param list<string> $paths
     * @param list<string> $pathExcludes
     *
     * @return array{matches: list<\Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentMatch>, hasLayers: bool}
     */
    public function resolve(
        array $paths,
        array $pathExcludes,
        AbsolutePath $projectRoot,
        SymbolPath $symbol,
    ): array {
        return $this->resolveFiles(
            $this->generatedFileFilter->filter($this->discoverFiles($paths, $pathExcludes)),
            $projectRoot,
            $symbol,
        );
    }

    /**
     * @param list<string> $paths
     * @param list<string> $pathExcludes
     *
     * @return array{matches: list<\Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentMatch>, hasLayers: bool}
     */
    public function resolveIncludingGenerated(
        array $paths,
        array $pathExcludes,
        AbsolutePath $projectRoot,
        SymbolPath $symbol,
    ): array {
        return $this->resolveFiles($this->discoverFiles($paths, $pathExcludes), $projectRoot, $symbol);
    }

    /**
     * @param list<SplFileInfo> $files
     *
     * @return array{matches: list<\Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentMatch>, hasLayers: bool}
     */
    private function resolveFiles(array $files, AbsolutePath $projectRoot, SymbolPath $symbol): array
    {
        $repository = $this->repositoryFactory->create();
        $collection = $this->collectFiles($files, $repository, $projectRoot);
        $logicalClassUniverse = [];
        foreach ($repository->allLogicalClasses() as $info) {
            $logicalClass = $info->subject?->logicalClassPath();
            if ($logicalClass !== null) {
                $logicalClassUniverse[$logicalClass->toCanonical()] = $logicalClass;
            }
        }
        $graph = $this->graphBuilder->build($collection->dependencies, array_values($logicalClassUniverse));

        $assignment = $this->layerAssignmentInspector->inspect($graph, $this->classPaths($repository), $symbol);

        return [
            'matches' => $assignment->matches,
            'hasLayers' => $assignment->hasLayers,
        ];
    }

    /**
     * @param list<string> $paths
     * @param list<string> $pathExcludes
     *
     * @return list<SplFileInfo>
     */
    private function discoverFiles(array $paths, array $pathExcludes): array
    {
        $fileDiscovery = $this->fileDiscoveryFactory->create($pathExcludes);
        $cwd = AbsolutePath::fromString((string) getcwd());
        $absolutePaths = array_map(
            static fn(string $raw): AbsolutePath => PathFactory::fromCliArgument($raw, $cwd),
            $paths,
        );

        return array_values(iterator_to_array($fileDiscovery->discover($absolutePaths), false));
    }

    /** @return list<SymbolPath> */
    private function classPaths(MetricRepositoryInterface $repository): array
    {
        $classPaths = [];
        foreach ($repository->all(SymbolType::Class_) as $classSymbol) {
            $classPaths[] = $classSymbol->symbolPath;
        }

        return $classPaths;
    }

    /** @param list<SplFileInfo> $files */
    private function collectFiles(
        array $files,
        MetricRepositoryInterface $repository,
        AbsolutePath $projectRoot,
    ): CollectionPhaseOutput {
        return $this->collectionOrchestrator->collect($files, $repository, $projectRoot);
    }
}
