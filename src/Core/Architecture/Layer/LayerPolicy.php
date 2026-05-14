<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Layer;

use Qualimetrix\Core\Architecture\Allow\AllowListEntry;
use Qualimetrix\Core\Architecture\Allow\AllowTarget;
use Qualimetrix\Core\Architecture\Allow\CaptureBinding;
use Qualimetrix\Core\Architecture\Allow\LayerSelector;

/**
 * Immutable allow-list of inter-layer dependencies.
 *
 * The policy is intentionally minimal:
 * - Same-layer dependencies (`A → A`) are always allowed, regardless of entry contents.
 * - Otherwise the entry list is traversed linearly; the first
 *   ({@see AllowListEntry}, {@see AllowTarget}) pair whose source selector
 *   matches `$from` AND whose target selector matches `$to` returns true.
 * - An unknown source layer (no matching source selector at all) means
 *   "no targets allowed".
 *
 * The traversal uses {@see LayerSelector::matchSource()} so captured source
 * selectors emit a {@see CaptureBinding} that is then threaded into
 * {@see LayerSelector::matchesTarget()} on the target side — in Step C every
 * binding is empty (captured selectors are parse-only on the source side until
 * Step E wires the population path).
 *
 * Cross-validation against the layer registry (which preserves declaration
 * order) is the factory's responsibility — this class trusts the input.
 */
final readonly class LayerPolicy
{
    /**
     * @var list<AllowListEntry>
     */
    private array $entries;

    /**
     * @param list<AllowListEntry> $entries Allow-list entries in declaration order.
     */
    public function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    /**
     * Returns true if a dependency from `$from` to `$to` is permitted.
     *
     * Same-layer dependencies (`$from === $to`) are always allowed.
     *
     * Contract: callers MUST first resolve `$from` and `$to` to concrete layer
     * names via {@see LayerRegistry::resolveLayer()}. A source layer name with
     * no matching source selector in the entry list is treated as "no targets
     * allowed" — this is the documented strict behaviour, not a defensive
     * fallback. Unresolved layers should be filtered by the caller before this
     * call (see {@see \Qualimetrix\Core\Architecture\CoverageMode} for how
     * out-of-layer classes are handled).
     */
    public function isAllowed(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        foreach ($this->entries as $entry) {
            $binding = $entry->source->matchSource($from);
            if ($binding === null) {
                continue;
            }

            foreach ($entry->targets as $target) {
                if ($target->target->matchesTarget($to, $binding)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns the list of allowed target descriptors for `$from`, drawn from
     * every entry whose source selector matches `$from`. Each descriptor is
     * the original selector string the user wrote — an exact layer name for
     * {@see Allow\SelectorKind::Exact}, a glob/captured pattern for the other
     * kinds. The recommendation surface in
     * {@see \Qualimetrix\Rules\Architecture\LayerViolationRule::buildRecommendation()}
     * renders them verbatim, which is accurate for all three kinds because
     * the original string is precisely the shape the user can copy back into
     * the YAML config to widen the policy.
     *
     * The list excludes `$from` itself (same-layer is implicit, not declared)
     * and is order-preserved + deduplicated on the original string.
     *
     * @return list<string>
     */
    public function allowedTargets(string $from): array
    {
        $result = [];
        $seen = [];

        foreach ($this->entries as $entry) {
            if ($entry->source->matchSource($from) === null) {
                continue;
            }

            foreach ($entry->targets as $target) {
                $descriptor = $target->target->originalString();

                if ($target->target->isExact() && $descriptor === $from) {
                    // Same-layer is implicit; do not surface in recommendations.
                    continue;
                }

                if (isset($seen[$descriptor])) {
                    continue;
                }

                $seen[$descriptor] = true;
                $result[] = $descriptor;
            }
        }

        return $result;
    }
}
