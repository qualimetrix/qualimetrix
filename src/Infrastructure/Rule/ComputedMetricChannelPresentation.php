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

    public function presentationFor(string $code): ?ChannelPresentation
    {
        $presentation = $this->inner->presentationFor($code);

        if ($presentation === null) {
            return null;
        }

        // A configured computed metric channel is keyed by its definition's own
        // name (FindingChannel::$code), so this lookup finds it
        // directly — no comparison against a producer rule name, and so no
        // re-derivation of which name marks the family.
        $definition = $this->definitionCatalog->find($code);

        if ($definition === null) {
            return $presentation;
        }

        // ComputedMetricRule::getDescription() describes the whole computed-metric
        // family, not this specific configured metric — the definition's own
        // description is preferred when present. A blank `description:` key is a
        // benign YAML omission, not a broken channel: the family's docs page is
        // still the right page, so falling back to the inner (family) presentation
        // keeps that page instead of discarding it — the symmetry this used to
        // draw with an unknown code doesn't hold, because an unknown code has no
        // real page to lose and a configured metric always does. Humanising the
        // metric's own name instead was rejected: that would reintroduce, in this
        // class, exactly the kind of privately-derived text
        // `docs/internal/plans/sarif-channel-descriptions.md` exists to remove —
        // whereas the inner presentation is a fact this capability already owns.
        return $definition->description === ''
            ? $presentation
            : new ChannelPresentation($definition->description, $presentation->docsPage);
    }
}
