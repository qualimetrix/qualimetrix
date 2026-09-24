<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\Observation\ShadowedClass;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Builds `architecture.potential-shadow`: a layer that loses the classes its
 * own criteria match to a layer declared earlier.
 *
 * Which matches may draw a shadow at all is decided upstream, by the walk
 * ({@see \Qualimetrix\Analysis\Policy\Architecture\Layer\LayerShadowing}); this
 * class only renders the evidence the walk recorded. It is a subject of its own
 * rather than a method of {@see DeclaredLayerReachability} because the shadow
 * evidence arrives as {@see ShadowedClass} values, a vocabulary no other
 * declaration verdict reads.
 *
 * @internal Consumed by {@see LayerDeclarationValidator}.
 */
final class PotentialShadowDiagnostic
{
    private const int SAMPLE_LIMIT = 5;

    /**
     * One finding per (assigned, shadowed) layer pair observed during the
     * class walk.
     *
     * Determinism: `metrics->all()` iteration order is not stable under
     * parallel collection. The per-pair sample is sorted lexicographically by
     * FQN and the pair list is sorted by (assigned, shadowed) before emission
     * so CI diffs are stable across runs.
     *
     * Each evidence entry already carries the primary criterion that matched
     * on each side, so no second walk over the layer list is necessary here.
     *
     * The severity is fixed at {@see Severity::Error} for the reason
     * {@see DeclaredLayerReachability} gives for every declaration verdict: the
     * channel belongs to a configuration validator and fails the run whatever
     * the word printed beside it.
     *
     * @param array<string, array<string, list<ShadowedClass>>> $shadowEvidence
     *
     * @return list<Finding>
     */
    public static function forShadows(array $shadowEvidence): array
    {
        $findings = [];

        foreach (self::sortedPairs($shadowEvidence) as $pair) {
            $entries = $pair['entries'];
            $sample = \array_slice($entries, 0, self::SAMPLE_LIMIT);
            $remaining = \count($entries) - \count($sample);

            $sampleList = implode(', ', array_map(static fn(ShadowedClass $entry): string => $entry->fqn, $sample));
            if ($remaining > 0) {
                $sampleList .= \sprintf(' ...and %d more', $remaining);
            }

            $findings[] = new Finding(
                location: Location::none(),
                subject: MetricSubject::aggregate(SymbolPath::forProject()),
                symbolPath: SymbolPath::forProject(),
                ruleName: LayerPolicyPreparationInterface::POTENTIAL_SHADOW_DIAGNOSTIC_NAME,
                code: LayerPolicyPreparationInterface::POTENTIAL_SHADOW_DIAGNOSTIC_NAME,
                message: \sprintf(
                    'Layer "%s" (%s) shadows layer "%s" (%s) for %d class(es) including %s. Run "qmx debug:layer-assignment <class>" to inspect specific cases.',
                    $pair['assigned'],
                    $sample[0]->assignedCriterion->describe(),
                    $pair['shadowed'],
                    $sample[0]->shadowedCriterion->describe(),
                    \count($entries),
                    $sampleList,
                ),
                severity: Severity::Error,
                recommendation: \sprintf(
                    'If layer "%s" should own these classes, declare it BEFORE "%s" (declaration order, first match wins). Otherwise tighten the patterns so the layers no longer overlap.',
                    $pair['shadowed'],
                    $pair['assigned'],
                ),
            );
        }

        return $findings;
    }

    /**
     * Flattens the evidence map into pairs ordered by (assigned, shadowed),
     * each with its own sample ordered by FQN.
     *
     * @param array<string, array<string, list<ShadowedClass>>> $shadowEvidence
     *
     * @return list<array{assigned: string, shadowed: string, entries: non-empty-list<ShadowedClass>}>
     */
    private static function sortedPairs(array $shadowEvidence): array
    {
        $pairs = [];
        foreach ($shadowEvidence as $assigned => $shadowedMap) {
            foreach ($shadowedMap as $shadowed => $entries) {
                // A pair exists only once a reportable shadow was recorded
                // for it, so the entry list is non-empty by construction.
                \assert($entries !== []);
                usort($entries, static fn(ShadowedClass $a, ShadowedClass $b): int => strcmp($a->fqn, $b->fqn));
                $pairs[] = [
                    'assigned' => (string) $assigned,
                    'shadowed' => (string) $shadowed,
                    'entries' => $entries,
                ];
            }
        }

        usort($pairs, static function (array $a, array $b): int {
            $cmp = strcmp($a['assigned'], $b['assigned']);

            return $cmp !== 0 ? $cmp : strcmp($a['shadowed'], $b['shadowed']);
        });

        return $pairs;
    }
}
