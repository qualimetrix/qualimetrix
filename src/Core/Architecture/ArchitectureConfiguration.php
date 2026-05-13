<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture;

use Qualimetrix\Core\Architecture\Layer\LayerPolicy;
use Qualimetrix\Core\Architecture\Layer\LayerRegistry;

/**
 * Typed holder for the resolved {@code architecture:} section of the user's
 * configuration.
 *
 * Encapsulates three pieces of information:
 * 1. The {@see LayerRegistry} — which classes belong to which layer.
 * 2. The {@see LayerPolicy} — which inter-layer dependencies are permitted.
 * 3. The {@see CoverageMode} — what to do with edges that involve unclassified
 *    classes.
 *
 * Validation and cross-checks happen in
 * {@see \Qualimetrix\Configuration\Architecture\ArchitectureConfigurationFactory};
 * this VO trusts its inputs.
 *
 * An "empty" configuration (no layers declared) signals that the architecture
 * rule should short-circuit during analysis. {@see isEmpty()} is the canonical
 * predicate for that check.
 *
 * Lives in the Core domain so that {@see \Qualimetrix\Core\Rule\AnalysisContext}
 * and rules (which cannot depend on Configuration) can reference it directly.
 */
final readonly class ArchitectureConfiguration
{
    public function __construct(
        private LayerRegistry $registry,
        private LayerPolicy $policy,
        private CoverageMode $coverage,
    ) {}

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
     * Returns true when no layers are declared. Architecture-aware rules should
     * skip work in that case.
     */
    public function isEmpty(): bool
    {
        return $this->registry->isEmpty();
    }
}
