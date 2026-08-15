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
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContextFactory;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassSet;
use Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerExpansionStage;
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

        $classes = $classUniverse instanceof ClassSet
            ? $classUniverse
            : new ClassSet(
                \is_array($classUniverse) ? array_values($classUniverse) : iterator_to_array($classUniverse, false),
                new ClassContextFactory(),
            );
        $configuration = $this->configured;
        if ($configuration->hasTemplates()) {
            $expansion = $this->expansionStage->expand($configuration->entries(), $classes, $configuration->maxExpandedLayers());
            $configuration = $configuration->withExpansion($expansion->expandedLayers, $expansion->emptyTemplateNames);
        }

        $configuration->registry()->bindGraph($graph);
        $this->prepared = $configuration;
    }

    public function inspect(DependencyGraphInterface $graph, iterable $classUniverse, SymbolPath $subject): LayerAssignment
    {
        $this->prepare($graph, $classUniverse);
        $configuration = $this->prepared;
        if ($configuration === null) {
            return new LayerAssignment([], false);
        }

        $matches = $configuration->registry()->resolveAll($subject);
        return new LayerAssignment(
            array_map(
                static function ($match): LayerAssignmentMatch {
                    $criteria = array_map(
                        static fn($criterion): string => $criterion->describe(),
                        $match->matchedCriteria,
                    );
                    if ($criteria === []) {
                        throw new LogicException('A layer assignment match requires at least one criterion.');
                    }

                    return new LayerAssignmentMatch($match->layerName, $criteria);
                },
                $matches,
            ),
            !$configuration->isEmpty(),
        );
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
