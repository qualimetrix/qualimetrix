<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;

/**
 * Writes what an `architecture.layer-violation` finding recommends: where the
 * edge could legally go.
 *
 * The guidance answers "what do I do with this edge", and needs nothing
 * the rule holds beyond the prepared policy — which is why they are here and
 * not in {@see LayerViolationRule}, whose remaining job is the per-edge verdict
 * and the evidence its two diagnostic collaborators read.
 *
 * @internal Consumed by {@see LayerViolationRule}.
 */
final class LayerRoutingGuidance
{
    public static function forForbiddenEdge(
        string $fromLayer,
        ArchitectureConfiguration $architecture,
    ): string {
        return self::routingGuidance($fromLayer, $architecture->policy()->allowedTargets($fromLayer));
    }

    /**
     * Produces the routing-guidance prefix. When no outgoing edges are
     * declared for the source layer, the guidance is the "no allowed targets"
     * sentinel; otherwise it lists the declared targets.
     *
     * @param list<string> $allowedTargets
     */
    private static function routingGuidance(string $fromLayer, array $allowedTargets): string
    {
        if ($allowedTargets === []) {
            return \sprintf(
                'Layer "%s" is not allowed to depend on any other declared layer.',
                $fromLayer,
            );
        }

        return \sprintf(
            'Allowed targets for layer "%s": %s. Consider routing through one of them.',
            $fromLayer,
            implode(', ', $allowedTargets),
        );
    }

}
