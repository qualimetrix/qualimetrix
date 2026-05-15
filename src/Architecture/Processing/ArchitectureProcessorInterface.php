<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Processing;

use LogicException;
use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Architecture\Domain\Layer\ClassSet;
use Qualimetrix\Architecture\Domain\Layer\LayerMatch;
use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Single coordinator for the rules-pipeline lifecycle of architecture analysis
 * (ADR 0008).
 *
 * **State machine.** One analysis run flows through four ordered stages:
 *
 * ```
 *   reset → bind → prepare → classify? → getPreparedConfiguration?
 *      ↺      ↺       ↺          ↺                 ↺
 * ```
 *
 * `reset` and `bind` are mandatory; `prepare` is mandatory before
 * `classify` / `getPreparedConfiguration` is meaningful. The cycle is
 * repeatable for back-to-back runs.
 *
 * **Invariants enforced by every implementation:**
 *
 * - {@see prepare()} MUST internally call `$config->registry()->bindGraph($graph)`.
 *   Skipping this would silently disable every graph-criterion ({@code attributes},
 *   {@code implements}, {@code extends}). Round-3 review caught this as
 *   load-bearing and it lives on the interface, not the implementer's discretion.
 * - {@see prepare()} takes the class set as a separate argument from the
 *   dependency graph: the graph carries only classes with at least one edge,
 *   while template expansion and unreachable-layer diagnostics need every
 *   class in the codebase.
 * - {@see classify()} yields {@see LayerMatch} entries (per-class assignment
 *   with matched criteria), NOT membership results (per-class-per-layer
 *   predicate). The debug command needs the former for parity with
 *   {@code qmx check}.
 * - Calling {@see bind()} after {@see prepare()} discards the prepared state;
 *   the caller must {@see prepare()} again before {@see classify()} succeeds.
 * - Calling {@see reset()} restores the empty state; subsequent
 *   {@see prepare()} / {@see classify()} throw until {@see bind()} is called.
 * - Misordered lifecycle throws {@see \LogicException} (fail-fast — it
 *   indicates a wiring bug at the DI level).
 *
 * @see \Qualimetrix\Architecture\Processing\ArchitectureProcessor Default
 *      implementation
 * @see \Qualimetrix\Architecture\Domain\ArchitectureConfiguration
 */
interface ArchitectureProcessorInterface
{
    /**
     * Stage A: Bind a fully-built {@see ArchitectureConfiguration} to the
     * processor. Called by {@code RuntimeConfigurator} after
     * {@code ConfigurationPipeline} has produced the configuration.
     *
     * Idempotent in the sense that subsequent calls replace the binding.
     * However, the previously-prepared state (if any) is discarded — the
     * caller must invoke {@see prepare()} again before {@see classify()}
     * returns matches.
     */
    public function bind(ArchitectureConfiguration $config): void;

    /**
     * Stage B: With the dependency graph and the project's class set both
     * available, expand template layers, bind the graph to the registry,
     * and store the prepared configuration for later consumption.
     *
     * Called by {@code AnalysisPipeline} after the Collection phase has
     * produced the dependency graph and built the class set.
     *
     * **MUST internally call `$config->registry()->bindGraph($graph)`** so
     * graph-criteria see fresh data — this is part of the interface
     * contract, not an implementer-discretion detail.
     *
     * @throws LogicException When invoked before {@see bind()}.
     */
    public function prepare(DependencyGraphInterface $graph, ClassSet $classes): void;

    /**
     * Stage C: Resolve the layer assignment for each class symbol in the
     * iterable. Returned matches contain the layer name and matched criteria
     * descriptors — enough for the debug command's per-class output and the
     * rule's shadow-evidence diagnostics.
     *
     * Classes that do not match any layer are skipped (their match list is
     * empty). The caller's iteration order is preserved; matches are yielded
     * in the order classes are consumed.
     *
     * @param iterable<SymbolPath> $classPaths Symbols to classify; one
     *                                         {@see LayerMatch} (or none)
     *                                         per symbol.
     *
     * @throws LogicException When invoked before {@see prepare()}.
     *
     * @return iterable<LayerMatch>
     */
    public function classify(iterable $classPaths): iterable;

    /**
     * Returns the prepared configuration when the processor is in the
     * post-{@see prepare()} state; returns {@code null} otherwise (pre-bind,
     * post-bind/pre-prepare, post-reset).
     *
     * Rule consumers read the configuration through this accessor and treat
     * {@code null} as no-op so they remain compatible with disabled-rule
     * runs and tests that wire the processor without preparing it.
     */
    public function getPreparedConfiguration(): ?ArchitectureConfiguration;

    /**
     * Restores the processor to its initial empty state. Idempotent — calling
     * it twice in a row, or before any {@see bind()}, is a safe no-op.
     *
     * After {@see reset()}, subsequent {@see prepare()} and {@see classify()}
     * calls throw {@see \LogicException} until a fresh {@see bind()}.
     */
    public function reset(): void;
}
