<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain;

use Qualimetrix\Architecture\Domain\Layer\LayerDefinition;
use Qualimetrix\Architecture\Domain\Layer\LayerPolicy;
use Qualimetrix\Architecture\Domain\Layer\LayerRegistry;
use Qualimetrix\Architecture\Domain\Layer\TemplateLayerDefinition;

/**
 * Typed holder for the resolved {@code architecture:} section of the user's
 * configuration.
 *
 * Encapsulates the resolved layer state:
 * 1. The {@see LayerRegistry} — which classes belong to which layer
 *    (post-expansion; identical to {@see entries()} for Phase-1 configs).
 * 2. The {@see LayerPolicy} — which inter-layer dependencies are permitted.
 * 3. The {@see CoverageMode} — what to do with edges that involve unclassified
 *    classes.
 * 4. The unexpanded {@see entries()} list (Phase 2 direction 2) — may
 *    interleave {@see LayerDefinition} and {@see TemplateLayerDefinition}.
 * 5. The {@see maxExpandedLayers()} ceiling — used by
 *    {@see \Qualimetrix\Architecture\Processing\LayerExpansionStage} to bound
 *    template expansion.
 * 6. The {@see emptyTemplateNames()} list — name templates that observed zero
 *    binding tuples; surfaced as {@code architecture.empty-template} warnings
 *    by the rule layer.
 *
 * **Phase-1 backward compatibility.** The {@code $entries} / {@code $maxExpandedLayers}
 * / {@code $emptyTemplateNames} parameters default to backward-compatible
 * values: when omitted, {@code entries} is derived from the registry's
 * definitions, {@code maxExpandedLayers} defaults to 500, and the
 * empty-template list is empty. Existing callers (and Phase-1 configs)
 * therefore see no behaviour change — the {@code Phase1ConfigCompatibilityTest}
 * pins this invariant.
 *
 * Validation and cross-checks happen in
 * {@see \Qualimetrix\Architecture\Configuration\ArchitectureConfigurationFactory};
 * this VO trusts its inputs.
 *
 * An "empty" configuration (no entries declared) signals that the architecture
 * rule should short-circuit during analysis. {@see isEmpty()} is the canonical
 * predicate for that check.
 *
 * Lives in the Core domain so that {@see \Qualimetrix\Core\Rule\AnalysisContext}
 * and rules (which cannot depend on Configuration) can reference it directly.
 */
final readonly class ArchitectureConfiguration
{
    public const int DEFAULT_MAX_EXPANDED_LAYERS = 500;

    /**
     * @var list<LayerDefinition|TemplateLayerDefinition>
     */
    public array $entries;

    /**
     * @param LayerRegistry $registry Post-expansion registry (contains only
     *                                static-resolved {@see LayerDefinition}s).
     *                                For Phase-1 configs without templates,
     *                                this is identical to the static layer
     *                                list. For Phase-2 configs with templates,
     *                                the {@see \Qualimetrix\Architecture\Processing\LayerExpansionStage}
     *                                walks the project's class set and
     *                                produces this registry via
     *                                {@see withExpansion()}.
     * @param list<LayerDefinition|TemplateLayerDefinition>|null $entries
     *                                                                    Unexpanded
     *                                                                    layer
     *                                                                    entries
     *                                                                    in
     *                                                                    declaration
     *                                                                    order.
     *                                                                    When
     *                                                                    null
     *                                                                    (Phase-1
     *                                                                    callers),
     *                                                                    derived
     *                                                                    from
     *                                                                    {@code $registry->definitions()}.
     * @param int $maxExpandedLayers Hard ceiling on template-layer
     *                               cumulative expansion. Must be >= 1.
     *                               Defaults to
     *                               {@see DEFAULT_MAX_EXPANDED_LAYERS}.
     * @param list<string> $emptyTemplateNames Name templates that produced
     *                                         zero concrete layers during
     *                                         expansion (drained by the
     *                                         rule layer into
     *                                         {@code architecture.empty-template}
     *                                         warnings).
     */
    public function __construct(
        private LayerRegistry $registry,
        private LayerPolicy $policy,
        private CoverageMode $coverage,
        ?array $entries = null,
        public int $maxExpandedLayers = self::DEFAULT_MAX_EXPANDED_LAYERS,
        public array $emptyTemplateNames = [],
    ) {
        $this->entries = $entries ?? $registry->definitions();
    }

    public function registry(): LayerRegistry
    {
        return $this->registry;
    }

    public function policy(): LayerPolicy
    {
        return $this->policy;
    }

    public function coverage(): CoverageMode
    {
        return $this->coverage;
    }

    /**
     * Returns the unexpanded entries list (mix of static layers and templates).
     *
     * @return list<LayerDefinition|TemplateLayerDefinition>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function maxExpandedLayers(): int
    {
        return $this->maxExpandedLayers;
    }

    /**
     * @return list<string>
     */
    public function emptyTemplateNames(): array
    {
        return $this->emptyTemplateNames;
    }

    /**
     * Returns true if at least one entry is a {@see TemplateLayerDefinition}.
     * Used by {@see \Qualimetrix\Analysis\Pipeline\AnalysisPipeline} to decide
     * whether to invoke the {@see \Qualimetrix\Architecture\Processing\LayerExpansionStage}.
     */
    public function hasTemplates(): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry instanceof TemplateLayerDefinition) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when no entries are declared. Architecture-aware rules
     * should skip work in that case. Phase 1 invariant: empty entries iff
     * empty registry.
     */
    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Returns a new VO carrying the post-expansion registry built from
     * {@code $expandedLayers} and the empty-template list from
     * {@code $emptyTemplateNames}. Preserves the original policy, coverage,
     * entries, and ceiling.
     *
     * Accepts primitive arrays rather than the Analysis-layer
     * {@code LayerExpansionResult} VO to keep the Core domain free of
     * Analysis-layer dependencies (Deptrac contract).
     *
     * The new registry borrows the original registry's {@see \Qualimetrix\Architecture\Domain\Layer\ClassContextFactory}
     * so the rule's {@code bindGraph()} invocation reaches the same factory
     * instance that backs every expanded layer's membership matching.
     *
     * @param list<LayerDefinition> $expandedLayers
     * @param list<string> $emptyTemplateNames
     */
    public function withExpansion(array $expandedLayers, array $emptyTemplateNames): self
    {
        return new self(
            registry: new LayerRegistry($expandedLayers, $this->registry->contextFactory()),
            policy: $this->policy,
            coverage: $this->coverage,
            entries: $this->entries,
            maxExpandedLayers: $this->maxExpandedLayers,
            emptyTemplateNames: $emptyTemplateNames,
        );
    }
}
