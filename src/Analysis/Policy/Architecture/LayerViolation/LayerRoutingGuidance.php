<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;

/**
 * Writes what an `architecture.layer-violation` finding recommends: where the
 * edge could legally go, plus the machine-readable description of the edge
 * itself for an agent consuming the report.
 *
 * Both halves answer "what do I do with this edge", and neither needs anything
 * the rule holds beyond the prepared policy — which is why they are here and
 * not in {@see LayerViolationRule}, whose remaining job is the per-edge verdict
 * and the evidence its two diagnostic collaborators read.
 *
 * @internal Consumed by {@see LayerViolationRule}.
 */
final class LayerRoutingGuidance
{
    public static function forForbiddenEdge(
        Dependency $dependency,
        string $fromLayer,
        string $toLayer,
        ArchitectureConfiguration $architecture,
    ): string {
        $guidance = self::routingGuidance($fromLayer, $architecture->policy()->allowedTargets($fromLayer));
        $payload = self::encodeDependencyPayload($dependency, $fromLayer, $toLayer);

        return $guidance . "\n" . 'Dep data: ' . $payload;
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

    /**
     * Serialises the structured dependency context the recommendation appends
     * for AI-agent consumers. Kept beside the textual prefix so the JSON shape
     * and the prose evolve together.
     */
    private static function encodeDependencyPayload(Dependency $dependency, string $fromLayer, string $toLayer): string
    {
        return json_encode(
            [
                'fromLayer' => $fromLayer,
                'toLayer' => $toLayer,
                'source' => $dependency->sourceLogical()->toString(),
                'target' => $dependency->targetLogical()->toString(),
                'type' => $dependency->type->value,
            ],
            \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );
    }
}
