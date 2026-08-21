<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Rule;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelPresentation;
use Qualimetrix\Analysis\Finding\Contract\ChannelPresentationInterface;

/**
 * Layers the `computed.*` / `health.*` description preference onto
 * {@see \Qualimetrix\Analysis\Finding\ChannelPresentationView}.
 *
 * A decorator rather than a constructor argument on the Finding-owned view:
 * `ComputedMetricDefinition` is owned by `Analysis\Evidence\ComputedMetrics`,
 * which itself depends on `Analysis\Finding` for `RuleInterface` — importing
 * it from Finding would close a dependency cycle
 * (`analysis-finding -> analysis-evidence-computedmetrics -> analysis-finding`).
 * Infrastructure already depends on both capabilities (see
 * {@see ChannelUniverse}), so the override belongs here instead.
 */
final readonly class ComputedMetricChannelPresentation implements ChannelPresentationInterface
{
    public function __construct(
        private ChannelPresentationInterface $inner,
        private ComputedMetricDefinitionCatalogInterface $definitionCatalog,
    ) {}

    public function presentationFor(string $violationCode): ?ChannelPresentation
    {
        $presentation = $this->inner->presentationFor($violationCode);

        if ($presentation === null) {
            return null;
        }

        // A configured computed metric channel is keyed by its definition's own
        // name (ViolationChannel::$violationCode), so this lookup finds it
        // directly — no comparison against a producer rule name, and so no
        // re-derivation of which name marks the family.
        $definition = $this->definitionCatalog->find($violationCode);

        if ($definition === null) {
            return $presentation;
        }

        // ComputedMetricRule::getDescription() describes the whole computed-metric
        // family, not this specific configured metric — the definition's own
        // description is preferred. An empty declared description is not display
        // text; see ChannelPresentationInterface for why that yields null rather
        // than the producer's generic fallback.
        return $definition->description === ''
            ? null
            : new ChannelPresentation($definition->description, $presentation->docsPage);
    }
}
