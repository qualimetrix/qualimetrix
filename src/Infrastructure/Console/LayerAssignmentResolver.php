<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignment;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentInspectorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectFilesInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

/**
 * Resolves a debug layer assignment from the same collected project state as analysis.
 *
 * An FQN absent from the run's declarations and graph ends is refused rather
 * than answered.
 */
final readonly class LayerAssignmentResolver
{
    public function __construct(
        private CollectionOrchestratorInterface $collectionOrchestrator,
        private DependencyGraphBuilderInterface $graphBuilder,
        private LayerAssignmentInspectorInterface $layerAssignmentInspector,
        private MetricRepositoryFactoryInterface $repositoryFactory,
        private ProjectFilesInterface $projectFiles,
    ) {}

    /** @qmx-ignore code-smell.boolean-argument -- policyDisabled is the final rule-selection fact forwarded into the assignment snapshot. */
    public function resolve(
        RunConfiguration $configuration,
        SymbolPath $symbol,
        bool $policyDisabled,
    ): LayerAssignment {
        $repository = $this->repositoryFactory->create();
        $collection = $this->collectFiles(
            $this->projectFiles->discover($configuration)->eligibleFiles,
            $repository,
            $configuration->projectRoot,
        );
        $classPaths = $this->classPaths($repository);
        $graph = $this->graphBuilder->build($collection->dependencies, $collection->classLikeDeclarations)->graph;

        $assignment = $this->layerAssignmentInspector->inspect($graph, $classPaths, $symbol, $policyDisabled);
        if ($assignment->declaredSpelling === null) {
            throw ConfigurationRefusal::aboutCommandLineInput('fqn', \sprintf(
                'Class "%s" is not among the declarations and graph ends observed under this configuration, '
                . 'so no layer assignment can be reported for it.',
                $symbol->toString(),
            ));
        }

        return $assignment;
    }

    /** @return list<SymbolPath> */
    private function classPaths(MetricRepositoryInterface $repository): array
    {
        $classPaths = [];
        foreach ($repository->allLogicalClasses() as $classSymbol) {
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
