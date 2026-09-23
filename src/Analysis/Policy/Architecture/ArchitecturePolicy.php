<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfigurationFactory;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignment;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentInspectorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignmentMatch;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ResolvedArchitecturePolicyInterface;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassSet;
use Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerExpansionStage;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerMatch;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerShadowing;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Instance-owned declared-layer policy configuration and prepared state. */
final class ArchitecturePolicy implements ArchitecturePolicyConfiguratorInterface, LayerPolicyPreparationInterface, LayerAssignmentInspectorInterface
{
    private ?ArchitectureConfiguration $configured = null;

    private ?ArchitectureConfiguration $prepared = null;

    private readonly LayerExpansionStage $expansionStage;

    public function __construct(
        private readonly ArchitectureConfigurationFactory $factory = new ArchitectureConfigurationFactory(),
        ?LayerExpansionStage $expansionStage = null,
    ) {
        $this->expansionStage = $expansionStage ?? new LayerExpansionStage();
    }

    public function resolve(ConfigurationDocument $document): ResolvedArchitecturePolicyInterface
    {
        return $this->factory->fromContributions($document->contributions('architecture'));
    }

    public function replace(ResolvedArchitecturePolicyInterface $policy): void
    {
        if (!$policy instanceof \Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureFactoryResult) {
            throw new LogicException('ArchitecturePolicy accepts only a policy resolved by its Architecture factory.');
        }

        $this->configured = $policy->configuration;
        $this->prepared = null;
    }

    /** Internal test seam retained while the direct policy tests are migrated. */
    public function bind(ArchitectureConfiguration $configuration): void
    {
        $this->configured = $configuration;
        $this->prepared = null;
    }

    public function prepare(DependencyGraphInterface $graph, iterable $classUniverse): void
    {
        $this->prepared = null;
        if ($this->configured === null) {
            throw new LogicException('ArchitecturePolicy::prepare() requires bind() to have been called.');
        }

        $configuration = $this->configured;

        // Materialised once, before the binding: the universe is read twice
        // from here — once as the set of declarations this run analysed, once
        // as the classes template observation walks — and a generator handed in
        // by a caller would be empty by the second read.
        $analysedClasses = \is_array($classUniverse)
            ? array_values($classUniverse)
            : iterator_to_array($classUniverse, false);

        // The run's single binding point: every reader of a class context,
        // template observation included, runs after this line. The universe
        // goes in with the graph, because a context that knows the graph but
        // not the universe cannot tell an inheritance chain that ended from one
        // that was cut at the edge of the analysed set.
        $configuration->registry()->bindGraph($graph, $analysedClasses);

        if ($configuration->hasTemplates()) {
            // One factory for the whole run. Observation and membership
            // matching must read the same contexts, or a layer is derived
            // from facts it is then matched against different ones.
            $classes = new ClassSet(
                $analysedClasses,
                $configuration->registry()->contextFactory(),
            );
            $expansion = $this->expansionStage->expand($configuration->entries(), $classes, $configuration->maxExpandedLayers());
            $configuration = $configuration->withExpansion($expansion->expandedLayers, $expansion->emptyTemplateNames);
        }

        $this->prepared = $configuration;
    }

    public function inspect(DependencyGraphInterface $graph, iterable $classUniverse, SymbolPath $subject): LayerAssignment
    {
        $this->prepare($graph, $classUniverse);
        $configuration = $this->prepared
            ?? throw new LogicException('ArchitecturePolicy::inspect() reached an unprepared policy after prepare() returned.');

        $registry = $configuration->registry();
        $established = $registry->establishedMatches($subject);

        return new LayerAssignment(
            array_map(self::assignmentMatch(...), $registry->resolveAll($subject)),
            !$configuration->isEmpty(),
            $registry->undecidedLayers($subject),
            $registry->chainStopsAt($subject),
            $registry->contenders($subject),
            ($established[0] ?? null)?->layerName,
            array_map(
                static fn(LayerMatch $match): string => $match->layerName,
                LayerShadowing::reportableShadows($established),
            ),
        );
    }

    private static function assignmentMatch(LayerMatch $match): LayerAssignmentMatch
    {
        $criteria = array_map(
            static fn($criterion): string => $criterion->describe(),
            $match->matchedCriteria,
        );
        if ($criteria === []) {
            throw new LogicException('A layer assignment match requires at least one criterion.');
        }

        return new LayerAssignmentMatch($match->layerName, $criteria);
    }

    /**
     * @param iterable<SymbolPath> $classPaths
     *
     * @return iterable<\Qualimetrix\Analysis\Policy\Architecture\Layer\LayerMatch>
     */
    public function classify(iterable $classPaths): iterable
    {
        if ($this->prepared === null) {
            throw new LogicException($this->configured === null
                ? 'ArchitecturePolicy::classify() requires bind() to have been called.'
                : 'ArchitecturePolicy::classify() requires prepare() to have been called.');
        }

        foreach ($classPaths as $classPath) {
            $matches = $this->prepared->registry()->resolveAll($classPath);
            if ($matches !== []) {
                yield $matches[0];
            }
        }
    }

    public function getPreparedConfiguration(): ?ArchitectureConfiguration
    {
        return $this->prepared;
    }

    public function reset(): void
    {
        $this->prepared = null;
    }
}
