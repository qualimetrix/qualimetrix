<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Layer;

use InvalidArgumentException;

/**
 * Immutable Value Object describing a single layer match for a class FQN.
 *
 * Returned by {@see LayerRegistry::resolveAll()} — one entry per layer
 * whose membership criteria match the class, in declaration order. The first
 * entry is the assignment; subsequent entries are "shadowed" layers that would
 * have matched if they were declared first.
 *
 * {@see matchedCriteria} carries the descriptors of every criterion kind that
 * fired (one per kind, in declaration order). For Phase-1-shape
 * patterns-only configs this list contains exactly one
 * {@see MatchedCriterionKind::Pattern} entry. Under multi-criterion membership
 * with {@see MatchMode::Any} the list may contain several entries — e.g. a
 * class that lives in {@code App\Repository} AND ends in {@code Repository}
 * produces both descriptors.
 *
 * Used by:
 * - {@see \Qualimetrix\Rules\Architecture\LayerViolationRule} for the
 *   {@code architecture.potential-shadow} evidence-based diagnostic.
 * - {@see \Qualimetrix\Infrastructure\Console\Command\Debug\LayerAssignmentCommand}
 *   for per-class introspection.
 */
final readonly class LayerMatch
{
    /**
     * @param list<MatchedCriterion> $matchedCriteria Non-empty list of matched
     *                                                criterion descriptors.
     */
    public function __construct(
        public string $layerName,
        public array $matchedCriteria,
    ) {
        if ($matchedCriteria === []) {
            throw new InvalidArgumentException(
                'LayerMatch must record at least one matched criterion — '
                . 'a match cannot occur with zero firing criteria.',
            );
        }
    }

    /**
     * Returns the first matched criterion — the most-prominent one in
     * declaration order. Used by diagnostic messages and the debug command
     * which surface a single descriptor when summarising the match.
     */
    public function primaryCriterion(): MatchedCriterion
    {
        return $this->matchedCriteria[0];
    }
}
