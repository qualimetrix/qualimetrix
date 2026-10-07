<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentInspectorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectFilesInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use SplFileInfo;

/**
 * Resolves a debug layer assignment from the same collected project state as analysis.
 *
 * An FQN naming no analysed declaration is refused rather than answered — see
 * {@see self::refuseUnknownClass()}.
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

    /**
     * @return array{matches: list<\Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentMatch>, hasLayers: bool, undecided: list<string>, chainStopsAt: list<string>, contenders: list<string>, firstEstablished: string|null, reportedShadows: list<string>}
     */
    public function resolve(
        RunConfiguration $configuration,
        SymbolPath $symbol,
    ): array {
        return $this->resolveFiles(
            $this->projectFiles->discover($configuration)->eligibleFiles,
            $configuration->projectRoot,
            $symbol,
        );
    }

    /**
     * @param list<SplFileInfo> $files
     *
     * @return array{matches: list<\Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentMatch>, hasLayers: bool, undecided: list<string>, chainStopsAt: list<string>, contenders: list<string>, firstEstablished: string|null, reportedShadows: list<string>}
     */
    private function resolveFiles(array $files, AbsolutePath $projectRoot, SymbolPath $symbol): array
    {
        $repository = $this->repositoryFactory->create();
        $collection = $this->collectFiles($files, $repository, $projectRoot);
        $classPaths = $this->classPaths($repository);
        $this->refuseUnknownClass($symbol, $classPaths);
        $graph = $this->graphBuilder->build($collection->dependencies, $collection->classLikeDeclarations)->graph;

        $assignment = $this->layerAssignmentInspector->inspect($graph, $classPaths, $symbol);

        return [
            'matches' => $assignment->matches,
            'hasLayers' => $assignment->hasLayers,
            // Carried, not dropped: an empty `matches` is two different facts
            // — every criterion answered "no", and some criterion the run
            // could not answer at all — and a reader handed only `matches`
            // cannot tell them apart. The same distinction reaches
            // `architecture.coverage-gap` from the same walk.
            'undecided' => $assignment->undecidedLayers,
            'chainStopsAt' => $assignment->chainStopsAt,
            'contenders' => $assignment->contenders,
            // Carried rather than read off `matches`: which later match is a
            // shadow depends on whether the matches in front of it were
            // established, and only the registry's walk knows that.
            'firstEstablished' => $assignment->firstEstablished,
            'reportedShadows' => $assignment->reportedShadows,
        ];
    }

    /**
     * Refuses an FQN that names no analysed declaration.
     *
     * Without this, a class the run never saw and a class the run saw but no
     * layer matched produce the identical `Assigned to: (no layer)` report —
     * two different facts under one form. The class set is already in hand
     * here (it is the same universe the inspector expands template layers
     * from), so the miss is knowable and is answered as a refusal.
     *
     * Membership is compared case-insensitively over ASCII, the way PHP folds
     * class names itself: layer *matching* is case-sensitive, but making the
     * refusal case-sensitive too would turn a differently-spelled real class
     * from an informational `(no layer)` answer into an error.
     *
     * @param list<SymbolPath> $classPaths
     *
     * @throws ConfigurationRefusal
     */
    private function refuseUnknownClass(SymbolPath $symbol, array $classPaths): void
    {
        $known = [];
        foreach ($classPaths as $classPath) {
            $known[strtolower($classPath->toCanonical())] = true;
        }

        if (isset($known[strtolower($symbol->toCanonical())])) {
            return;
        }

        throw ConfigurationRefusal::aboutCommandLineInput('fqn', \sprintf(
            'Class "%s" is not among the %d classes analysed under this configuration, '
            . 'so no layer assignment can be reported for it. A class outside `paths`, '
            . 'removed by `exclude`, or skipped as generated is not analysed.',
            $symbol->toString(),
            \count($classPaths),
        ));
    }

    /** @return list<SymbolPath> */
    private function classPaths(MetricRepositoryInterface $repository): array
    {
        $classPaths = [];
        foreach ($repository->all(SymbolLevel::Class_) as $classSymbol) {
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
