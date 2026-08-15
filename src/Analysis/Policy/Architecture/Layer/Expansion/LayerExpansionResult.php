<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion;

use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;

/**
 * Output of {@see LayerExpansionStage::expand()}.
 *
 * Carries two pieces of information consumed downstream:
 *
 * 1. {@see expandedLayers} — the fully resolved declaration-order list of
 *    {@see LayerDefinition} instances that {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry}
 *    should use. Static (non-template) entries pass through verbatim;
 *    each {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition}
 *    is replaced by one concrete {@see LayerDefinition} per observed binding
 *    tuple, inserted at the template's position in lexicographic order of
 *    captured values.
 *
 * 2. {@see emptyTemplateNames} — name templates that matched zero classes
 *    during expansion. {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule}
 *    drains this list into one {@code architecture.empty-template} warning
 *    diagnostic per template name at the end of the run.
 *
 * Consumed by
 * {@see \Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy::prepare()}
 * which folds the result into the prepared configuration; the rule layer
 * reads the post-expansion state through
 * {@see \Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy::getPreparedConfiguration()}.
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
