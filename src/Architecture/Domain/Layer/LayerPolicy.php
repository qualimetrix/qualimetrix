<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain\Layer;

use Qualimetrix\Architecture\Domain\Allow\AllowListEntry;
use Qualimetrix\Architecture\Domain\Allow\AllowTarget;
use Qualimetrix\Architecture\Domain\Allow\CaptureBinding;
use Qualimetrix\Architecture\Domain\Allow\LayerSelector;
use Qualimetrix\Core\Dependency\DependencyType;

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
 * selectors emit a populated {@see CaptureBinding} that is threaded into
 * {@see LayerSelector::matchesTarget()} on the target side; this is how
 * {@code 'app-{m}': ['domain-{m}']} accepts {@code app-Order → domain-Order}
 * but rejects {@code app-Order → domain-Inventory}.
 *
 * When {@see AllowTarget::$allowCrossInstance} is true the policy substitutes
 * an empty binding into the target match call, letting any same-shape target
 * layer satisfy the entry regardless of the source-side capture values.
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
     * Same-layer dependencies (`$from === $to`) are always allowed regardless
     * of `$type`.
     *
     * `$type` carries the dependency-edge kind from the collector and is
     * checked against {@see AllowTarget::$relations}:
     *
     * - `$type === null` — relation filter is bypassed entirely. Used by tests
     *   and callers that only care about layer-pair reachability without
     *   per-edge granularity.
     * - `$type !== null` — only allow targets whose `relations` field is null
     *   (any relation) OR contains `$type` accept the edge. The Step G
     *   `relations:` long-form key populates `relations`; bare-string targets
     *   leave it null and continue to accept any relation kind.
     *
     * **Overlapping union semantics.** When several entries match the same
     * (source, target) layer pair, the walk visits each target in declaration
     * order and returns true on the first match-and-accept. A bare-string
     * target (relations=null) therefore dominates any sibling long-form target
     * with `relations:` — if the user wrote `allow: { domain: [contracts] }`
     * the edge is accepted regardless of subsequent `[target: contracts,
     * relations: [extends]]` siblings.
     *
     * Contract: callers MUST first resolve `$from` and `$to` to concrete layer
     * names via {@see LayerRegistry::resolveLayer()}. A source layer name with
     * no matching source selector in the entry list is treated as "no targets
     * allowed" — this is the documented strict behaviour, not a defensive
     * fallback. Unresolved layers should be filtered by the caller before this
     * call (see {@see \Qualimetrix\Architecture\Domain\CoverageMode} for how
     * out-of-layer classes are handled).
     */
    public function isAllowed(string $from, string $to, ?DependencyType $type = null): bool
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
                $effectiveBinding = $target->allowCrossInstance ? CaptureBinding::empty() : $binding;
                if (!$target->target->matchesTarget($to, $effectiveBinding)) {
                    continue;
                }

                if (!self::acceptsRelation($target, $type)) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Edge-relation gate. Returns true when:
     *
     * - the caller did not provide an edge type (callers that bypass the
     *   filter), OR
     * - the target accepts any relation (`relations === null`), OR
     * - the target's whitelist contains the edge type.
     */
    private static function acceptsRelation(AllowTarget $target, ?DependencyType $type): bool
    {
        if ($type === null || $target->relations === null) {
            return true;
        }

        return \in_array($type, $target->relations, true);
    }

    /**
     * Returns the list of allowed target descriptors for `$from`, drawn from
     * every entry whose source selector matches `$from`. Each descriptor is
     * the original selector string the user wrote — an exact layer name for
     * {@see Allow\SelectorKind::Exact}, a glob/captured pattern for the other
     * kinds. The recommendation surface in
     * {@see \Qualimetrix\Architecture\Rules\LayerViolationRule::buildRecommendation()}
     * renders them verbatim, which is accurate for all three kinds because
     * the original string is precisely the shape the user can copy back into
     * the YAML config to widen the policy.
     *
     * When a target carries a non-null {@see AllowTarget::$relations} list,
     * the descriptor gains a {@code "(relations: extends, implements)"}
     * trailer so the rule's recommendation message tells the user that
     * routing the violating edge through this target only works for specific
     * relation kinds — otherwise the message would mislead users into
     * believing the bare layer name accepts any dependency kind.
     *
     * The list excludes `$from` itself (same-layer is implicit, not declared)
     * and is order-preserved + deduplicated on the full descriptor
     * (so {@code "vendor"} and {@code "vendor (relations: extends)"} are
     * treated as distinct entries — both are surfaced when both shapes coexist
     * in the allow-list).
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
                $original = $target->target->originalString();

                if ($target->target->isExact() && $original === $from) {
                    // Same-layer is implicit; do not surface in recommendations.
                    continue;
                }

                $descriptor = self::renderTargetDescriptor($original, $target);

                if (isset($seen[$descriptor])) {
                    continue;
                }

                $seen[$descriptor] = true;
                $result[] = $descriptor;
            }
        }

        return $result;
    }

    /**
     * Combines the target's user-written selector string with a
     * {@code "(relations: ...)"} suffix when {@see AllowTarget::$relations}
     * is non-null. Aliases are NOT round-tripped here — the user sees the
     * expanded {@see DependencyType} values, which is the only honest
     * representation of the actual filter.
     */
    private static function renderTargetDescriptor(string $original, AllowTarget $target): string
    {
        if ($target->relations === null) {
            return $original;
        }

        $values = implode(', ', array_map(
            static fn(DependencyType $type): string => $type->value,
            $target->relations,
        ));

        return \sprintf('%s (relations: %s)', $original, $values);
    }
}
