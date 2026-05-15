<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Architecture;

use Qualimetrix\Architecture\Domain\Layer\LayerDefinition;

/**
 * Output of {@see LayerExpansionStage::expand()}.
 *
 * Carries two pieces of information consumed downstream:
 *
 * 1. {@see expandedLayers} — the fully resolved declaration-order list of
 *    {@see LayerDefinition} instances that {@see \Qualimetrix\Architecture\Domain\Layer\LayerRegistry}
 *    should use. Static (non-template) entries pass through verbatim;
 *    each {@see \Qualimetrix\Architecture\Domain\Layer\TemplateLayerDefinition}
 *    is replaced by one concrete {@see LayerDefinition} per observed binding
 *    tuple, inserted at the template's position in lexicographic order of
 *    captured values.
 *
 * 2. {@see emptyTemplateNames} — name templates that matched zero classes
 *    during expansion. {@see \Qualimetrix\Rules\Architecture\LayerViolationRule}
 *    drains this list into one {@code architecture.empty-template} warning
 *    diagnostic per template name at the end of the run.
 *
 * Stored on
 * {@see \Qualimetrix\Architecture\Domain\ArchitectureConfigurationHolder}
 * between collection and rule execution; deliberately not threaded through
 * {@see \Qualimetrix\Core\Rule\AnalysisContext} so the rule-context boundary
 * stays Phase-1-stable.
 */
final readonly class LayerExpansionResult
{
    /**
     * @param list<LayerDefinition> $expandedLayers
     * @param list<string> $emptyTemplateNames
     */
    public function __construct(
        public array $expandedLayers,
        public array $emptyTemplateNames,
    ) {}

    /**
     * Convenience factory for the empty-expansion case (e.g. no templates in
     * configuration, or the rule short-circuits).
     */
    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * @return list<LayerDefinition>
     */
    public function expandedLayers(): array
    {
        return $this->expandedLayers;
    }

    /**
     * @return list<string>
     */
    public function emptyTemplateNames(): array
    {
        return $this->emptyTemplateNames;
    }
}
