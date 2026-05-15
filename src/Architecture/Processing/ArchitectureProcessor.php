<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Processing;

use LogicException;
use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Architecture\Domain\Layer\ClassSet;
use Qualimetrix\Architecture\Domain\Layer\LayerMatch;
use Qualimetrix\Core\Dependency\DependencyGraphInterface;

/**
 * Default implementation of {@see ArchitectureProcessorInterface}.
 *
 * Holds the rules-pipeline lifecycle for one analysis run at a time. The
 * state machine is documented on the interface; this class enforces the
 * invariants with explicit guards that throw {@see LogicException} on
 * misordered calls.
 *
 * **Lifecycle states.** The processor moves through three internal states:
 *
 * - {@code empty}: no configuration bound; {@code preparedConfiguration} is
 *   null. Reached at construction time and after {@see reset()}.
 * - {@code bound}: configuration bound via {@see bind()} but not yet prepared.
 *   {@code classify()} and {@code getPreparedConfiguration()} are not
 *   meaningful in this state.
 * - {@code prepared}: configuration bound AND {@see prepare()} has run.
 *   Template expansion has happened, the registry's graph binding is set,
 *   and {@code classify()} / {@code getPreparedConfiguration()} succeed.
 *
 * The transitions allowed from each state are encoded in the guard methods.
 *
 * **One {@see LayerExpansionStage} per processor instance.** The expansion
 * stage owns nothing run-specific and is safe to share across runs; only
 * its inputs (entries, class set, ceiling) change. Reusing the same instance
 * is intentional — closes the M3 finding from the 8-agent review which
 * tracked the spawned-per-run {@code ClassContextFactory} as an outstanding
 * lifecycle drift.
 */
final class ArchitectureProcessor implements ArchitectureProcessorInterface
{
    private ?ArchitectureConfiguration $boundConfiguration = null;

    private ?ArchitectureConfiguration $preparedConfiguration = null;

    private readonly LayerExpansionStage $expansionStage;

    public function __construct(?LayerExpansionStage $expansionStage = null)
    {
        $this->expansionStage = $expansionStage ?? new LayerExpansionStage();
    }

    public function bind(ArchitectureConfiguration $config): void
    {
        $this->boundConfiguration = $config;
        // Re-binding invalidates any prior prepared state. Caller must
        // prepare() again before classify() returns matches.
        $this->preparedConfiguration = null;
    }

    public function prepare(DependencyGraphInterface $graph, ClassSet $classes): void
    {
        if ($this->boundConfiguration === null) {
            throw new LogicException(
                'ArchitectureProcessor::prepare() requires bind() to have been called',
            );
        }

        $configuration = $this->boundConfiguration;

        if ($configuration->hasTemplates()) {
            $expansion = $this->expansionStage->expand(
                $configuration->entries(),
                $classes,
                $configuration->maxExpandedLayers(),
            );

            $configuration = $configuration->withExpansion(
                $expansion->expandedLayers,
                $expansion->emptyTemplateNames,
            );
        }

        // Load-bearing per ADR 0008 §2: graph-criteria fire only after the
        // registry's ClassContextFactory is bound to the current run's graph.
        $configuration->registry()->bindGraph($graph);

        $this->preparedConfiguration = $configuration;
    }

    public function classify(iterable $classPaths): iterable
    {
        if ($this->boundConfiguration === null) {
            throw new LogicException(
                'ArchitectureProcessor::classify() requires bind() to have been called',
            );
        }

        if ($this->preparedConfiguration === null) {
            throw new LogicException(
                'ArchitectureProcessor::classify() requires prepare() to have been called',
            );
        }

        $registry = $this->preparedConfiguration->registry();

        foreach ($classPaths as $classPath) {
            $matches = $registry->resolveAll($classPath);
            if ($matches === []) {
                continue;
            }

            // resolveAll returns every shadowed layer; the assignment is
            // the first entry. Per ADR 0008 §2 classify() yields LayerMatch
            // (per-class assignment), so we surface the head of the list.
            yield $matches[0];
        }
    }

    public function getPreparedConfiguration(): ?ArchitectureConfiguration
    {
        return $this->preparedConfiguration;
    }

    public function reset(): void
    {
        $this->boundConfiguration = null;
        $this->preparedConfiguration = null;
    }
}
